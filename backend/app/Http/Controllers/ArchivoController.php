<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ArchivoController extends Controller
{
    /**
     * Sube UN archivo a S3 y guarda su registro.
     * El frontend manda una petición por archivo para mostrar el progreso de cada uno.
     */
    public function subir(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $maxMb = config('easydocs.max_subida_mb');

        $request->validate([
            'archivo' => ['required', 'file', 'max:'.($maxMb * 1024)],
            'carpeta_padre' => ['nullable', 'string'],
        ], [
            'archivo.required' => 'Selecciona un archivo.',
            'archivo.file' => 'No se recibió un archivo válido.',
            'archivo.uploaded' => "El archivo no se pudo subir. El máximo es de {$maxMb} MB.",
            'archivo.max' => "El archivo supera el máximo de {$maxMb} MB.",
        ]);

        $padreId = $request->input('carpeta_padre') ?: null;

        if ($padreId !== null && ! $this->carpetaActiva($padreId, $usuarioId)) {
            return $this->noEncontrado('La carpeta de destino no existe.');
        }

        $archivo = $request->file('archivo');
        $nombre = $this->limpiarNombre($archivo->getClientOriginalName()) ?: 'archivo';
        $tipoMime = $archivo->getMimeType() ?: 'application/octet-stream';
        $tamano = $archivo->getSize();

        $id = (string) Str::uuid7();
        $extension = $this->extension($nombre);
        $s3Key = "usuarios/{$usuarioId}/{$id}".($extension ? ".{$extension}" : '');

        // 1. Primero el objeto en S3...
        $flujo = fopen($archivo->getRealPath(), 'r');
        $guardado = $this->disco()->put($s3Key, $flujo, ['ContentType' => $tipoMime]);

        if (is_resource($flujo)) {
            fclose($flujo);
        }

        if (! $guardado) {
            return response()->json(['message' => 'No se pudo guardar el archivo. Intenta de nuevo.'], 500);
        }

        // 2. ...después el registro. Si la BD falla, se borra de S3 para no dejar basura.
        try {
            DB::insert(
                'INSERT INTO archivos (id, usuario_id, carpeta_padre, nombre, s3_key, tipo_mime, tamano)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $usuarioId, $padreId, $nombre, $s3Key, $tipoMime, $tamano]
            );
        } catch (Throwable $e) {
            $this->disco()->delete($s3Key);
            throw $e;
        }

        return response()->json(['archivo' => $this->archivoPropio($id, $usuarioId)], 201);
    }

    /**
     * Descarga un archivo propio o compartido con el usuario.
     *
     * El archivo pasa por Laravel: se valida la sesión en cada descarga y nunca se
     * genera un enlace de S3 que alguien pueda copiar y abrir sin cuenta.
     * Siempre se descarga (attachment), nunca se muestra en el navegador.
     */
    public function descargar(Request $request, string $id): Response
    {
        $archivo = $this->archivoAccesible($id, $this->usuarioId($request));

        if (! $archivo) {
            return $this->noEncontrado('El archivo no existe o no tienes acceso.');
        }

        if (! $this->disco()->exists($archivo->s3_key)) {
            return $this->noEncontrado('El archivo ya no está disponible en el almacenamiento.');
        }

        // Archivos grandes: que PHP no corte la transferencia a la mitad
        set_time_limit(0);

        return $this->disco()->download($archivo->s3_key, $archivo->nombre, [
            'Content-Type' => $archivo->tipo_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Pueden cambiar el nombre el dueño y quienes tienen permiso de escritura.
     */
    public function renombrar(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoEditable($id, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe o no tienes permiso para modificarlo.');
        }

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
        ]);

        $nombre = $this->limpiarNombre($datos['nombre']);

        if ($nombre === '') {
            return $this->errorValidacion('nombre', 'El nombre es obligatorio.');
        }

        DB::update('UPDATE archivos SET nombre = ? WHERE id = ?', [$nombre, $id]);

        return response()->json(['archivo' => ['id' => $id, 'nombre' => $nombre]]);
    }

    /**
     * Mueve un archivo a otra carpeta (o a la raíz con carpeta_padre = null).
     * Solo el dueño puede moverlo, y solo a una carpeta suya que no esté en la papelera.
     * En S3 no cambia nada: la ubicación solo existe en la base de datos.
     */
    public function mover(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $archivo = $this->archivoPropio($id, $usuarioId);

        if (! $archivo) {
            return $this->noEncontrado('El archivo no existe.');
        }

        $request->validate([
            'carpeta_padre' => ['nullable', 'string'],
        ]);

        $destino = $request->input('carpeta_padre') ?: null;

        if ($destino !== null && ! $this->carpetaActiva($destino, $usuarioId)) {
            return $this->noEncontrado('La carpeta de destino no existe.');
        }

        DB::update(
            'UPDATE archivos SET carpeta_padre = ? WHERE id = ? AND usuario_id = ?',
            [$destino, $id, $usuarioId]
        );

        return response()->json([
            'archivo' => ['id' => $id, 'carpeta_padre' => $destino],
            'origen' => $archivo->carpeta_padre,
        ]);
    }

    /**
     * Archivo activo del que el usuario es dueño, o null.
     */
    private function archivoPropio(string $id, int $usuarioId): ?object
    {
        return DB::selectOne(
            'SELECT id, carpeta_padre, nombre, tipo_mime, tamano, creado_en, actualizado_en
             FROM archivos
             WHERE id = ? AND usuario_id = ? AND papelera = 0',
            [$id, $usuarioId]
        );
    }

    /**
     * Archivo activo que el usuario puede ver: es el dueño O se lo compartieron.
     */
    private function archivoAccesible(string $id, int $usuarioId): ?object
    {
        return DB::selectOne(
            'SELECT a.id, a.nombre, a.s3_key, a.tipo_mime
             FROM archivos a
             LEFT JOIN archivos_compartidos ac
                    ON ac.archivo_id = a.id AND ac.usuario_id = ?
             WHERE a.id = ? AND a.papelera = 0
               AND (a.usuario_id = ? OR ac.id IS NOT NULL)',
            [$usuarioId, $id, $usuarioId]
        );
    }

    /**
     * Archivo activo que el usuario puede modificar: es el dueño O tiene permiso de escritura.
     */
    private function archivoEditable(string $id, int $usuarioId): ?object
    {
        return DB::selectOne(
            "SELECT a.id
             FROM archivos a
             LEFT JOIN archivos_compartidos ac
                    ON ac.archivo_id = a.id AND ac.usuario_id = ? AND ac.permiso = 'escritura'
             WHERE a.id = ? AND a.papelera = 0
               AND (a.usuario_id = ? OR ac.id IS NOT NULL)",
            [$usuarioId, $id, $usuarioId]
        );
    }

    private function disco(): Filesystem
    {
        return Storage::disk();
    }

    /**
     * Quita separadores de ruta y caracteres de control, y recorta a 255 caracteres.
     */
    private function limpiarNombre(string $nombre): string
    {
        $nombre = preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', $nombre) ?? '';

        return mb_substr(trim($nombre), 0, 255);
    }

    private function extension(string $nombre): string
    {
        $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));

        return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : '';
    }
}
