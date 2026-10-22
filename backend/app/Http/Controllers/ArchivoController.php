<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ArchivoController extends Controller
{
    /**
     * Sube UN archivo al disco y guarda su documento.
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
        $ruta = "usuarios/{$usuarioId}/{$id}".($extension ? ".{$extension}" : '');

        // 1. Primero el archivo en el disco...
        $flujo = fopen($archivo->getRealPath(), 'r');
        $guardado = $this->disco()->put($ruta, $flujo);

        if (is_resource($flujo)) {
            fclose($flujo);
        }

        if (! $guardado) {
            return response()->json(['message' => 'No se pudo guardar el archivo. Intenta de nuevo.'], 500);
        }

        // 2. ...después el documento. Si la BD falla, se borra del disco para no dejar basura.
        $ahora = $this->ahora();

        try {
            $this->base->archivos->insertOne([
                '_id' => $id,
                'usuario_id' => $usuarioId,
                'carpeta_padre' => $padreId,
                'nombre' => $nombre,
                'ruta' => $ruta,
                'tipo_mime' => $tipoMime,
                'tamano' => $tamano,
                'papelera' => false,
                'fecha_papelera' => null,
                'creado_en' => $ahora,
                'actualizado_en' => $ahora,
                // (object) para que se guarde como subdocumento {} y no como arreglo []
                'metadatos' => (object) [],
                'compartidos' => [],
                'enlaces_publicos' => [],
            ]);
        } catch (Throwable $e) {
            $this->disco()->delete($ruta);
            throw $e;
        }

        return response()->json(['archivo' => $this->json($this->archivoPropio($id, $usuarioId))], 201);
    }

    /**
     * Descarga un archivo propio o compartido con el usuario.
     *
     * El archivo pasa por Laravel: se valida la sesión en cada descarga y nunca se
     * expone la ubicación real del archivo. Siempre se descarga (attachment),
     * nunca se muestra en el navegador.
     */
    public function descargar(Request $request, string $id): Response
    {
        $archivo = $this->archivoAccesible($id, $this->usuarioId($request));

        if (! $archivo) {
            return $this->noEncontrado('El archivo no existe o no tienes acceso.');
        }

        if (! $this->disco()->exists($archivo->ruta)) {
            return $this->noEncontrado('El archivo ya no está disponible en el almacenamiento.');
        }

        // Archivos grandes: que PHP no corte la transferencia a la mitad
        set_time_limit(0);

        return $this->disco()->download($archivo->ruta, $archivo->nombre, [
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

        $this->base->archivos->updateOne(
            ['_id' => $id],
            ['$set' => ['nombre' => $nombre, 'actualizado_en' => $this->ahora()]]
        );

        return response()->json(['archivo' => ['id' => $id, 'nombre' => $nombre]]);
    }

    /**
     * Mueve un archivo a otra carpeta (o a la raíz con carpeta_padre = null).
     * Solo el dueño puede moverlo, y solo a una carpeta suya que no esté en la papelera.
     * En el disco no cambia nada: la ubicación solo existe en la base de datos.
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

        $this->base->archivos->updateOne(
            ['_id' => $id, 'usuario_id' => $usuarioId],
            ['$set' => ['carpeta_padre' => $destino, 'actualizado_en' => $this->ahora()]]
        );

        return response()->json([
            'archivo' => ['id' => $id, 'carpeta_padre' => $destino],
            'origen' => $archivo->carpeta_padre,
        ]);
    }

    /**
     * Archivo activo del que el usuario es dueño, o null.
     */
    private function archivoPropio(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->archivos->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => false],
            ['projection' => [
                'carpeta_padre' => 1, 'nombre' => 1, 'tipo_mime' => 1, 'tamano' => 1,
                'creado_en' => 1, 'actualizado_en' => 1,
            ]]
        );
    }

    /**
     * Archivo activo que el usuario puede ver: es el dueño O se lo compartieron.
     * Antes era un LEFT JOIN con archivos_compartidos; ahora la compartición está
     * dentro del mismo documento.
     */
    private function archivoAccesible(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->archivos->findOne(
            [
                '_id' => $id,
                'papelera' => false,
                '$or' => [
                    ['usuario_id' => $usuarioId],
                    ['compartidos.usuario_id' => $usuarioId],
                ],
            ],
            ['projection' => ['nombre' => 1, 'ruta' => 1, 'tipo_mime' => 1]]
        );
    }

    /**
     * Archivo activo que el usuario puede modificar: es el dueño O tiene permiso de escritura.
     * $elemMatch exige que usuario y permiso estén en el MISMO elemento del arreglo.
     */
    private function archivoEditable(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->archivos->findOne(
            [
                '_id' => $id,
                'papelera' => false,
                '$or' => [
                    ['usuario_id' => $usuarioId],
                    ['compartidos' => ['$elemMatch' => ['usuario_id' => $usuarioId, 'permiso' => 'escritura']]],
                ],
            ],
            ['projection' => ['_id' => 1]]
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
