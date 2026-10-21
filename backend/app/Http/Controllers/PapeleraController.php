<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Papelera (eliminación lógica en cascada).
 *
 * - Enviar una carpeta a la papelera marca papelera = 1 en ella y en TODO su contenido
 *   activo (subcarpetas y archivos, a cualquier profundidad), con la misma fecha_papelera.
 * - fecha_papelera identifica cada operación: al restaurar solo regresa lo que se
 *   eliminó junto con el elemento. Lo que ya estaba en la papelera se queda ahí.
 * - Si la carpeta original de un elemento ya no está activa, se restaura en la raíz.
 * - Eliminar definitivamente borra los registros (en cascada) y los objetos de S3.
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

        $carpetas = DB::select(
            'SELECT c.id, c.nombre, c.fecha_papelera
             FROM carpetas c
             LEFT JOIN carpetas p ON p.id = c.carpeta_padre
             WHERE c.usuario_id = ? AND c.papelera = 1
               AND (p.id IS NULL OR p.papelera = 0 OR p.fecha_papelera <> c.fecha_papelera)
             ORDER BY c.fecha_papelera DESC, c.nombre',
            [$usuarioId]
        );

        $archivos = DB::select(
            'SELECT a.id, a.nombre, a.tipo_mime, a.tamano, a.fecha_papelera
             FROM archivos a
             LEFT JOIN carpetas p ON p.id = a.carpeta_padre
             WHERE a.usuario_id = ? AND a.papelera = 1
               AND (p.id IS NULL OR p.papelera = 0 OR p.fecha_papelera <> a.fecha_papelera)
             ORDER BY a.fecha_papelera DESC, a.nombre',
            [$usuarioId]
        );

        return response()->json(['carpetas' => $carpetas, 'archivos' => $archivos]);
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

        DB::transaction(function () use ($id, $usuarioId) {
            $fecha = now()->format('Y-m-d H:i:s');

            // La carpeta y sus subcarpetas que siguen activas
            $ids = $this->idsDescendientes($id, 'c.papelera = 0');
            $marcas = $this->marcas($ids);

            DB::update(
                "UPDATE carpetas SET papelera = 1, fecha_papelera = ?
                 WHERE usuario_id = ? AND id IN ($marcas)",
                [$fecha, $usuarioId, ...$ids]
            );

            DB::update(
                "UPDATE archivos SET papelera = 1, fecha_papelera = ?
                 WHERE usuario_id = ? AND papelera = 0 AND carpeta_padre IN ($marcas)",
                [$fecha, $usuarioId, ...$ids]
            );
        });

        return response()->json(['mensaje' => 'Carpeta enviada a la papelera.']);
    }

    public function enviarArchivo(Request $request, string $id): JsonResponse
    {
        $actualizados = DB::update(
            'UPDATE archivos SET papelera = 1, fecha_papelera = ?
             WHERE id = ? AND usuario_id = ? AND papelera = 0',
            [now()->format('Y-m-d H:i:s'), $id, $this->usuarioId($request)]
        );

        if ($actualizados === 0) {
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

        DB::transaction(function () use ($carpeta, $usuarioId, &$enRaiz) {
            $destino = $this->destinoRestauracion($carpeta->carpeta_padre, $usuarioId);
            $enRaiz = $carpeta->carpeta_padre !== null && $destino === null;

            // Si ya existe una carpeta activa con ese nombre en el destino, se renombra
            $nombre = $this->nombreLibre($carpeta->nombre, $destino, $usuarioId);

            DB::update(
                'UPDATE carpetas SET carpeta_padre = ?, nombre = ? WHERE id = ?',
                [$destino, $nombre, $carpeta->id]
            );

            // Solo lo que se eliminó en la misma operación (misma fecha_papelera)
            $ids = $this->idsDescendientes(
                $carpeta->id,
                'c.papelera = 1 AND c.fecha_papelera = ?',
                [$carpeta->fecha_papelera]
            );
            $marcas = $this->marcas($ids);

            DB::update(
                "UPDATE carpetas SET papelera = 0, fecha_papelera = NULL
                 WHERE usuario_id = ? AND id IN ($marcas)",
                [$usuarioId, ...$ids]
            );

            DB::update(
                "UPDATE archivos SET papelera = 0, fecha_papelera = NULL
                 WHERE usuario_id = ? AND papelera = 1 AND fecha_papelera = ?
                   AND carpeta_padre IN ($marcas)",
                [$usuarioId, $carpeta->fecha_papelera, ...$ids]
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

        $archivo = DB::selectOne(
            'SELECT id, carpeta_padre FROM archivos WHERE id = ? AND usuario_id = ? AND papelera = 1',
            [$id, $usuarioId]
        );

        if (! $archivo) {
            return $this->noEncontrado('El archivo no está en la papelera.');
        }

        $destino = $this->destinoRestauracion($archivo->carpeta_padre, $usuarioId);

        DB::update(
            'UPDATE archivos SET papelera = 0, fecha_papelera = NULL, carpeta_padre = ? WHERE id = ?',
            [$destino, $archivo->id]
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
        $marcas = $this->marcas($ids);

        $claves = array_column(DB::select(
            "SELECT s3_key FROM archivos WHERE usuario_id = ? AND carpeta_padre IN ($marcas)",
            [$usuarioId, ...$ids]
        ), 's3_key');

        DB::transaction(function () use ($ids, $marcas, $usuarioId) {
            DB::delete(
                "DELETE FROM archivos WHERE usuario_id = ? AND carpeta_padre IN ($marcas)",
                [$usuarioId, ...$ids]
            );

            $this->borrarCarpetasDeHojasARaiz(
                "usuario_id = ? AND id IN ($marcas)",
                [$usuarioId, ...$ids]
            );
        });

        $this->borrarDeS3($claves);

        return response()->json(['mensaje' => 'Carpeta eliminada definitivamente.']);
    }

    public function eliminarArchivo(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        $archivo = DB::selectOne(
            'SELECT id, s3_key FROM archivos WHERE id = ? AND usuario_id = ? AND papelera = 1',
            [$id, $usuarioId]
        );

        if (! $archivo) {
            return $this->noEncontrado('El archivo no está en la papelera.');
        }

        DB::delete('DELETE FROM archivos WHERE id = ?', [$archivo->id]);
        $this->borrarDeS3([$archivo->s3_key]);

        return response()->json(['mensaje' => 'Archivo eliminado definitivamente.']);
    }

    public function vaciar(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        // El contenido de una carpeta en la papelera también está en la papelera,
        // así que basta con borrar todo lo que tenga papelera = 1.
        $claves = array_column(DB::select(
            'SELECT s3_key FROM archivos WHERE usuario_id = ? AND papelera = 1',
            [$usuarioId]
        ), 's3_key');

        DB::transaction(function () use ($usuarioId) {
            DB::delete('DELETE FROM archivos WHERE usuario_id = ? AND papelera = 1', [$usuarioId]);
            $this->borrarCarpetasDeHojasARaiz('usuario_id = ? AND papelera = 1', [$usuarioId]);
        });

        $this->borrarDeS3($claves);

        return response()->json(['mensaje' => 'Papelera vaciada.']);
    }

    // ---------------------------------------------------------------------
    // Auxiliares
    // ---------------------------------------------------------------------

    /**
     * Id de la carpeta y de sus subcarpetas (a cualquier profundidad).
     * $condicion filtra por qué subcarpetas se sigue bajando (alias "c").
     */
    private function idsDescendientes(string $id, string $condicion = '1 = 1', array $parametros = []): array
    {
        $filas = DB::select(
            "WITH RECURSIVE arbol AS (
                SELECT id FROM carpetas WHERE id = ?
                UNION ALL
                SELECT c.id FROM carpetas c
                JOIN arbol a ON c.carpeta_padre = a.id
                WHERE $condicion
            )
            SELECT id FROM arbol",
            [$id, ...$parametros]
        );

        return array_column($filas, 'id');
    }

    /**
     * Borra carpetas empezando por las que no tienen subcarpetas y subiendo nivel
     * por nivel. MySQL limita las cascadas a 15 niveles, así nunca se llega a ese límite.
     */
    private function borrarCarpetasDeHojasARaiz(string $condicion, array $parametros): void
    {
        do {
            $borradas = DB::delete(
                "DELETE FROM carpetas
                 WHERE $condicion
                   AND id NOT IN (
                       SELECT carpeta_padre FROM (
                           SELECT carpeta_padre FROM carpetas WHERE carpeta_padre IS NOT NULL
                       ) AS padres
                   )",
                $parametros
            );
        } while ($borradas > 0);
    }

    /**
     * Se borra de S3 después de confirmar la BD: si S3 falla, el registro ya no existe
     * y el objeto huérfano queda en el log para borrarlo a mano.
     */
    private function borrarDeS3(array $claves): void
    {
        if ($claves === []) {
            return;
        }

        if (! Storage::disk()->delete($claves)) {
            Log::warning('No se pudieron borrar algunos objetos de S3.', ['claves' => $claves]);
        }
    }

    private function carpetaEnPapelera(string $id, int $usuarioId): ?object
    {
        return DB::selectOne(
            'SELECT id, carpeta_padre, nombre, fecha_papelera
             FROM carpetas WHERE id = ? AND usuario_id = ? AND papelera = 1',
            [$id, $usuarioId]
        );
    }

    /**
     * La carpeta original si sigue activa; si no, la raíz (null).
     */
    private function destinoRestauracion(?string $padreId, int $usuarioId): ?string
    {
        if ($padreId === null) {
            return null;
        }

        return $this->carpetaActiva($padreId, $usuarioId) ? $padreId : null;
    }

    /**
     * "Tareas" -> "Tareas (1)", "Tareas (2)"... si ya hay una carpeta activa con ese nombre.
     */
    private function nombreLibre(string $nombre, ?string $padreId, int $usuarioId): string
    {
        [$filtroPadre, $parametrosPadre] = $this->filtroPadre($padreId);
        $candidato = $nombre;

        for ($i = 1; ; $i++) {
            $existe = DB::selectOne(
                "SELECT id FROM carpetas
                 WHERE usuario_id = ? AND $filtroPadre AND papelera = 0 AND nombre = ?",
                [$usuarioId, ...$parametrosPadre, $candidato]
            );

            if (! $existe) {
                return $candidato;
            }

            $candidato = mb_substr($nombre, 0, 240)." ($i)";
        }
    }

    /**
     * "?, ?, ?" para usar un arreglo dentro de IN (...).
     */
    private function marcas(array $valores): string
    {
        return implode(', ', array_fill(0, count($valores), '?'));
    }
}
