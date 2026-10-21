<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enlaces públicos: cualquiera con el enlace puede ver el archivo, sin iniciar sesión.
 *
 * - Solo el dueño crea y desactiva el enlace. Un archivo tiene a lo más un enlace activo.
 * - Desactivar no borra la fila (activo = 0, revocado_en = fecha): queda el historial.
 * - El enlace usa un token aleatorio de 64 caracteres, imposible de adivinar.
 * - El archivo pasa por Laravel: al desactivar el enlace deja de funcionar al instante,
 *   y nunca se expone una URL de S3.
 * - Si el dueño manda el archivo a la papelera, el enlace deja de funcionar
 *   (y vuelve a funcionar si lo restaura).
 */
class EnlacePublicoController extends Controller
{
    /**
     * Tipos que el navegador muestra sin ejecutar código. Cualquier otro tipo
     * (HTML, SVG, ...) se descarga: mostrarlo desde nuestro dominio permitiría
     * ejecutar scripts con la sesión de quien abre el enlace.
     */
    private const TIPOS_VISIBLES = [
        'application/pdf',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
        'text/plain',
        'video/mp4', 'video/webm', 'video/ogg',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm',
    ];

    public function ver(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        return response()->json(['enlace' => $this->enlaceActivo($archivoId)]);
    }

    public function crear(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        // Si ya hay uno activo, se devuelve el mismo
        if ($enlace = $this->enlaceActivo($archivoId)) {
            return response()->json(['enlace' => $enlace]);
        }

        DB::insert(
            'INSERT INTO enlaces_publicos (id, archivo_id, token) VALUES (?, ?, ?)',
            [(string) Str::uuid7(), $archivoId, bin2hex(random_bytes(32))]
        );

        return response()->json(['enlace' => $this->enlaceActivo($archivoId)], 201);
    }

    public function desactivar(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        DB::update(
            'UPDATE enlaces_publicos SET activo = 0, revocado_en = ?
             WHERE archivo_id = ? AND activo = 1',
            [now()->format('Y-m-d H:i:s'), $archivoId]
        );

        return response()->json(['enlace' => null]);
    }

    /**
     * Abre el archivo de un enlace público. No requiere sesión.
     */
    public function abrir(string $token): Response
    {
        $archivo = DB::selectOne(
            'SELECT a.nombre, a.s3_key, a.tipo_mime
             FROM enlaces_publicos e
             JOIN archivos a ON a.id = e.archivo_id
             WHERE e.token = ? AND e.activo = 1 AND a.papelera = 0',
            [$token]
        );

        if (! $archivo || ! Storage::disk()->exists($archivo->s3_key)) {
            return redirect('/paginas/enlace-no-disponible.html');
        }

        set_time_limit(0);

        $visible = in_array($archivo->tipo_mime, self::TIPOS_VISIBLES, true);

        return Storage::disk()->response(
            $archivo->s3_key,
            $archivo->nombre,
            [
                'Content-Type' => $visible ? $archivo->tipo_mime : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
                // Que buscadores no indexen archivos privados de los usuarios
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
            $visible ? 'inline' : 'attachment'
        );
    }

    private function enlaceActivo(string $archivoId): ?object
    {
        return DB::selectOne(
            'SELECT token, creado_en FROM enlaces_publicos WHERE archivo_id = ? AND activo = 1',
            [$archivoId]
        );
    }

    private function archivoPropio(string $id, int $usuarioId): ?object
    {
        return DB::selectOne(
            'SELECT id FROM archivos WHERE id = ? AND usuario_id = ? AND papelera = 0',
            [$id, $usuarioId]
        );
    }
}
