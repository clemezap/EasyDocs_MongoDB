<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enlaces públicos: cualquiera con el enlace puede ver el archivo, sin iniciar sesión.
 *
 * - Viven en el arreglo "enlaces_publicos" del archivo (antes tabla aparte).
 * - Solo el dueño crea y desactiva el enlace. Un archivo tiene a lo más un enlace activo.
 * - Desactivar no saca el elemento (activo = false, revocado_en = fecha): queda el historial.
 * - El enlace usa un token aleatorio de 64 caracteres, imposible de adivinar; el índice
 *   único uq_archivos_enlace_token garantiza que no se repita entre archivos.
 * - El archivo pasa por Laravel: al desactivar el enlace deja de funcionar al instante,
 *   y nunca se expone la ubicación real del archivo.
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
        $archivo = $this->archivoPropio($archivoId, $this->usuarioId($request));

        if (! $archivo) {
            return $this->noEncontrado('El archivo no existe.');
        }

        return response()->json(['enlace' => $this->enlaceActivo($archivo)]);
    }

    public function crear(Request $request, string $archivoId): JsonResponse
    {
        $archivo = $this->archivoPropio($archivoId, $this->usuarioId($request));

        if (! $archivo) {
            return $this->noEncontrado('El archivo no existe.');
        }

        // Si ya hay uno activo, se devuelve el mismo
        if ($enlace = $this->enlaceActivo($archivo)) {
            return response()->json(['enlace' => $enlace]);
        }

        // El filtro evita que dos peticiones al mismo tiempo dejen dos enlaces activos
        $this->base->archivos->updateOne(
            ['_id' => $archivoId, 'enlaces_publicos.activo' => ['$ne' => true]],
            ['$push' => ['enlaces_publicos' => [
                'id' => (string) Str::uuid7(),
                'token' => bin2hex(random_bytes(32)),
                'activo' => true,
                'creado_en' => $this->ahora(),
                'revocado_en' => null,
            ]]]
        );

        $archivo = $this->archivoPropio($archivoId, $this->usuarioId($request));

        return response()->json(['enlace' => $this->enlaceActivo($archivo)], 201);
    }

    public function desactivar(Request $request, string $archivoId): JsonResponse
    {
        if (! $this->archivoPropio($archivoId, $this->usuarioId($request))) {
            return $this->noEncontrado('El archivo no existe.');
        }

        // arrayFilters: cambia solo los elementos del arreglo que siguen activos
        $this->base->archivos->updateOne(
            ['_id' => $archivoId],
            ['$set' => [
                'enlaces_publicos.$[enlace].activo' => false,
                'enlaces_publicos.$[enlace].revocado_en' => $this->ahora(),
            ]],
            ['arrayFilters' => [['enlace.activo' => true]]]
        );

        return response()->json(['enlace' => null]);
    }

    /**
     * Abre el archivo de un enlace público. No requiere sesión.
     */
    public function abrir(string $token): Response
    {
        // $elemMatch: token y activo deben ser del MISMO enlace del arreglo
        $archivo = $this->base->archivos->findOne(
            [
                'enlaces_publicos' => ['$elemMatch' => ['token' => $token, 'activo' => true]],
                'papelera' => false,
            ],
            ['projection' => ['nombre' => 1, 'ruta' => 1, 'tipo_mime' => 1]]
        );

        if (! $archivo || ! Storage::disk()->exists($archivo->ruta)) {
            return redirect('/paginas/enlace-no-disponible.html');
        }

        set_time_limit(0);

        $visible = in_array($archivo->tipo_mime, self::TIPOS_VISIBLES, true);

        return Storage::disk()->response(
            $archivo->ruta,
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

    /**
     * El enlace activo del archivo ({token, creado_en}) o null.
     */
    private function enlaceActivo(object $archivo): ?array
    {
        foreach ($archivo->enlaces_publicos as $enlace) {
            if ($enlace->activo) {
                return $this->json(['token' => $enlace->token, 'creado_en' => $enlace->creado_en]);
            }
        }

        return null;
    }

    private function archivoPropio(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->archivos->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => false],
            ['projection' => ['enlaces_publicos' => 1]]
        );
    }
}
