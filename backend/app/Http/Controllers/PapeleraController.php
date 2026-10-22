<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Driver\Session;

use function MongoDB\with_transaction;

/**
 * Papelera (eliminación lógica en cascada).
 *
 * - Enviar una carpeta a la papelera marca papelera = true en ella y en TODO su contenido
 *   activo (subcarpetas y archivos, a cualquier profundidad), con la misma fecha_papelera.
 * - fecha_papelera identifica cada operación: al restaurar solo regresa lo que se
 *   eliminó junto con el elemento. Lo que ya estaba en la papelera se queda ahí.
 * - Si la carpeta original de un elemento ya no está activa, se restaura en la raíz.
 * - Eliminar definitivamente borra los documentos y los archivos del disco. Sin llaves
 *   foráneas no hay cascada automática: el contenido se borra explícitamente.
 * - Las operaciones de varios documentos van en una transacción (Atlas es un replica set).
 */
class PapeleraController extends Controller
{
    /**
     * Elementos en la papelera. Solo se muestra el elemento "principal" de cada
     * eliminación: aquel cuya carpeta padre no se eliminó en la misma operación.
     */
    public function listar(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        $carpetas = $this->base->carpetas->aggregate([
            ['$match' => ['usuario_id' => $usuarioId, 'papelera' => true]],
            ...$this->soloPrincipales(),
            ['$sort' => ['fecha_papelera' => -1, 'nombre' => 1]],
            ['$project' => ['nombre' => 1, 'fecha_papelera' => 1]],
        ]);

        $archivos = $this->base->archivos->aggregate([
            ['$match' => ['usuario_id' => $usuarioId, 'papelera' => true]],
            ...$this->soloPrincipales(),
            ['$sort' => ['fecha_papelera' => -1, 'nombre' => 1]],
            ['$project' => ['nombre' => 1, 'tipo_mime' => 1, 'tamano' => 1, 'fecha_papelera' => 1]],
        ]);

        return response()->json([
            'carpetas' => $this->json($carpetas->toArray()),
            'archivos' => $this->json($archivos->toArray()),
        ]);
    }

    // ---------------------------------------------------------------------
    // Enviar a la papelera
    // ---------------------------------------------------------------------

    public function enviarCarpeta(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->carpetaActiva($id, $usuarioId)) {
            return $this->noEncontrado('La carpeta no existe.');
        }

        $this->transaccion(function (Session $sesion) use ($id, $usuarioId) {
            $fecha = $this->ahora();
            $marca = ['papelera' => true, 'fecha_papelera' => $fecha, 'actualizado_en' => $fecha];

            // La carpeta y sus subcarpetas que siguen activas
            $ids = $this->idsDescendientes($id, ['papelera' => false], $sesion);

            $this->base->carpetas->updateMany(
                ['usuario_id' => $usuarioId, '_id' => ['$in' => $ids]],
                ['$set' => $marca],
                ['session' => $sesion]
            );

            $this->base->archivos->updateMany(
                ['usuario_id' => $usuarioId, 'papelera' => false, 'carpeta_padre' => ['$in' => $ids]],
                ['$set' => $marca],
                ['session' => $sesion]
            );
        });

        return response()->json(['mensaje' => 'Carpeta enviada a la papelera.']);
    }

    public function enviarArchivo(Request $request, string $id): JsonResponse
    {
        $fecha = $this->ahora();

        $resultado = $this->base->archivos->updateOne(
            ['_id' => $id, 'usuario_id' => $this->usuarioId($request), 'papelera' => false],
            ['$set' => ['papelera' => true, 'fecha_papelera' => $fecha, 'actualizado_en' => $fecha]]
        );

        if ($resultado->getMatchedCount() === 0) {
            return $this->noEncontrado('El archivo no existe.');
        }

        return response()->json(['mensaje' => 'Archivo enviado a la papelera.']);
    }

    // ---------------------------------------------------------------------
    // Restaurar
    // ---------------------------------------------------------------------

    public function restaurarCarpeta(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $carpeta = $this->carpetaEnPapelera($id, $usuarioId);

        if (! $carpeta) {
            return $this->noEncontrado('La carpeta no está en la papelera.');
        }

        $enRaiz = false;

        $this->transaccion(function (Session $sesion) use ($carpeta, $usuarioId, &$enRaiz) {
            $destino = $this->destinoRestauracion($carpeta->carpeta_padre, $usuarioId);
            $enRaiz = $carpeta->carpeta_padre !== null && $destino === null;
            $ahora = $this->ahora();

            // Si ya existe una carpeta activa con ese nombre en el destino, se renombra
            $nombre = $this->nombreLibre($carpeta->nombre, $destino, $usuarioId);

            $this->base->carpetas->updateOne(
                ['_id' => $carpeta->_id],
                ['$set' => ['carpeta_padre' => $destino, 'nombre' => $nombre]],
                ['session' => $sesion]
            );

            // Solo lo que se eliminó en la misma operación (misma fecha_papelera)
            $ids = $this->idsDescendientes(
                $carpeta->_id,
                ['papelera' => true, 'fecha_papelera' => $carpeta->fecha_papelera],
                $sesion
            );

            $this->base->carpetas->updateMany(
                ['usuario_id' => $usuarioId, '_id' => ['$in' => $ids]],
                ['$set' => ['papelera' => false, 'fecha_papelera' => null, 'actualizado_en' => $ahora]],
                ['session' => $sesion]
            );

            $this->base->archivos->updateMany(
                [
                    'usuario_id' => $usuarioId,
                    'papelera' => true,
                    'fecha_papelera' => $carpeta->fecha_papelera,
                    'carpeta_padre' => ['$in' => $ids],
                ],
                ['$set' => ['papelera' => false, 'fecha_papelera' => null, 'actualizado_en' => $ahora]],
                ['session' => $sesion]
            );
        });

        return response()->json([
            'mensaje' => $enRaiz
                ? 'Carpeta restaurada en Principal (su carpeta original ya no existe).'
                : 'Carpeta restaurada.',
        ]);
    }

    public function restaurarArchivo(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        $archivo = $this->base->archivos->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => true],
            ['projection' => ['carpeta_padre' => 1]]
        );

        if (! $archivo) {
            return $this->noEncontrado('El archivo no está en la papelera.');
        }

        $destino = $this->destinoRestauracion($archivo->carpeta_padre, $usuarioId);

        $this->base->archivos->updateOne(
            ['_id' => $archivo->_id],
            ['$set' => [
                'papelera' => false,
                'fecha_papelera' => null,
                'carpeta_padre' => $destino,
                'actualizado_en' => $this->ahora(),
            ]]
        );

        return response()->json([
            'mensaje' => $archivo->carpeta_padre !== null && $destino === null
                ? 'Archivo restaurado en Principal (su carpeta original ya no existe).'
                : 'Archivo restaurado.',
        ]);
    }

    // ---------------------------------------------------------------------
    // Eliminar definitivamente
    // ---------------------------------------------------------------------

    public function eliminarCarpeta(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->carpetaEnPapelera($id, $usuarioId)) {
            return $this->noEncontrado('La carpeta no está en la papelera.');
        }

        // Todo lo que está dentro, sin importar cuándo se eliminó
        $ids = $this->idsDescendientes($id);
        $filtroArchivos = ['usuario_id' => $usuarioId, 'carpeta_padre' => ['$in' => $ids]];

        $rutas = $this->rutas($filtroArchivos);

        // Sin ON DELETE CASCADE: se borran los archivos y las carpetas explícitamente.
        // Ya no hace falta borrar "de hojas a raíz" (el límite de 15 niveles era de MySQL).
        $this->transaccion(function (Session $sesion) use ($ids, $filtroArchivos, $usuarioId) {
            $this->base->archivos->deleteMany($filtroArchivos, ['session' => $sesion]);
            $this->base->carpetas->deleteMany(
                ['usuario_id' => $usuarioId, '_id' => ['$in' => $ids]],
                ['session' => $sesion]
            );
        });

        $this->borrarDelDisco($rutas);

        return response()->json(['mensaje' => 'Carpeta eliminada definitivamente.']);
    }

    public function eliminarArchivo(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        $archivo = $this->base->archivos->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => true],
            ['projection' => ['ruta' => 1]]
        );

        if (! $archivo) {
            return $this->noEncontrado('El archivo no está en la papelera.');
        }

        // Sus compartidos y enlaces están dentro del documento: se van con él
        $this->base->archivos->deleteOne(['_id' => $archivo->_id]);
        $this->borrarDelDisco([$archivo->ruta]);

        return response()->json(['mensaje' => 'Archivo eliminado definitivamente.']);
    }

    public function vaciar(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        // El contenido de una carpeta en la papelera también está en la papelera,
        // así que basta con borrar todo lo que tenga papelera = true.
        $filtro = ['usuario_id' => $usuarioId, 'papelera' => true];
        $rutas = $this->rutas($filtro);

        $this->transaccion(function (Session $sesion) use ($filtro) {
            $this->base->archivos->deleteMany($filtro, ['session' => $sesion]);
            $this->base->carpetas->deleteMany($filtro, ['session' => $sesion]);
        });

        $this->borrarDelDisco($rutas);

        return response()->json(['mensaje' => 'Papelera vaciada.']);
    }

    // ---------------------------------------------------------------------
    // Auxiliares
    // ---------------------------------------------------------------------

    /**
     * Etapas que dejan pasar un elemento si no tiene carpeta padre, si su padre está
     * activo o si su padre se eliminó en otra operación (otra fecha_papelera).
     * Es el LEFT JOIN carpetas p ... WHERE (p.id IS NULL OR ...) de MySQL.
     */
    private function soloPrincipales(): array
    {
        return [
            ['$lookup' => [
                'from' => 'carpetas',
                'localField' => 'carpeta_padre',
                'foreignField' => '_id',
                'as' => 'padre',
            ]],
            ['$match' => ['$expr' => ['$or' => [
                ['$eq' => [['$size' => '$padre'], 0]],
                ['$eq' => [['$first' => '$padre.papelera'], false]],
                ['$ne' => [['$first' => '$padre.fecha_papelera'], '$fecha_papelera']],
            ]]]],
        ];
    }

    /**
     * Id de la carpeta y de sus subcarpetas (a cualquier profundidad).
     * $graphLookup baja por carpeta_padre (equivalente al WITH RECURSIVE de MySQL);
     * $condicion decide por qué subcarpetas se sigue bajando (restrictSearchWithMatch).
     */
    private function idsDescendientes(string $id, array $condicion = [], ?Session $sesion = null): array
    {
        $busqueda = [
            'from' => 'carpetas',
            'startWith' => '$_id',
            'connectFromField' => '_id',
            'connectToField' => 'carpeta_padre',
            'as' => 'descendientes',
        ];

        if ($condicion !== []) {
            $busqueda['restrictSearchWithMatch'] = $condicion;
        }

        $resultado = $this->base->carpetas->aggregate([
            ['$match' => ['_id' => $id]],
            ['$graphLookup' => $busqueda],
            ['$project' => ['ids' => '$descendientes._id']],
        ], $sesion ? ['session' => $sesion] : [])->toArray();

        return $resultado === [] ? [] : [$id, ...$resultado[0]->ids];
    }

    /**
     * Ejecuta $trabajo en una transacción. with_transaction reintenta solo si
     * MongoDB reporta un error transitorio (por ejemplo, un conflicto de escritura).
     */
    private function transaccion(callable $trabajo): void
    {
        $sesion = app(Client::class)->startSession();

        try {
            with_transaction($sesion, $trabajo);
        } finally {
            $sesion->endSession();
        }
    }

    private function rutas(array $filtro): array
    {
        $archivos = $this->base->archivos->find($filtro, ['projection' => ['ruta' => 1]]);

        return array_map(fn ($archivo) => $archivo->ruta, $archivos->toArray());
    }

    /**
     * Se borra del disco después de confirmar la BD: si el disco falla, el documento ya
     * no existe y el archivo huérfano queda en el log para borrarlo a mano.
     */
    private function borrarDelDisco(array $rutas): void
    {
        if ($rutas === []) {
            return;
        }

        if (! Storage::disk()->delete($rutas)) {
            Log::warning('No se pudieron borrar algunos archivos del disco.', ['rutas' => $rutas]);
        }
    }

    private function carpetaEnPapelera(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->carpetas->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => true],
            ['projection' => ['carpeta_padre' => 1, 'nombre' => 1, 'fecha_papelera' => 1]]
        );
    }

    /**
     * La carpeta original si sigue activa; si no, la raíz (null).
     */
    private function destinoRestauracion(?string $padreId, ObjectId $usuarioId): ?string
    {
        if ($padreId === null) {
            return null;
        }

        return $this->carpetaActiva($padreId, $usuarioId) ? $padreId : null;
    }

    /**
     * "Tareas" -> "Tareas (1)", "Tareas (2)"... si ya hay una carpeta activa con ese nombre.
     */
    private function nombreLibre(string $nombre, ?string $padreId, ObjectId $usuarioId): string
    {
        $candidato = $nombre;

        for ($i = 1; ; $i++) {
            $existe = $this->base->carpetas->findOne(
                ['usuario_id' => $usuarioId, 'carpeta_padre' => $padreId, 'papelera' => false, 'nombre' => $candidato],
                ['projection' => ['_id' => 1]]
            );

            if (! $existe) {
                return $candidato;
            }

            $candidato = mb_substr($nombre, 0, 240)." ($i)";
        }
    }
}
