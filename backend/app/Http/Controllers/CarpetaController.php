<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CarpetaController extends Controller
{
    /**
     * Contenido de una carpeta (o de la raíz si no se manda ?carpeta=).
     * Devuelve la carpeta actual, la ruta desde la raíz, sus subcarpetas y sus archivos.
     */
    public function contenido(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $carpetaId = $request->query('carpeta') ?: null;

        $carpeta = null;
        $ruta = [];

        if ($carpetaId !== null) {
            $carpeta = $this->carpetaActiva($carpetaId, $usuarioId);

            if (! $carpeta) {
                return $this->noEncontrado('La carpeta no existe.');
            }

            $ruta = $this->ruta($carpetaId, $usuarioId);
        }

        [$filtroPadre, $parametrosPadre] = $this->filtroPadre($carpetaId);

        $carpetas = DB::select(
            "SELECT id, nombre, creado_en, actualizado_en
             FROM carpetas
             WHERE usuario_id = ? AND $filtroPadre AND papelera = 0
             ORDER BY nombre",
            [$usuarioId, ...$parametrosPadre]
        );

        $archivos = DB::select(
            "SELECT a.id, a.nombre, a.tipo_mime, a.tamano, a.creado_en, a.actualizado_en,
                    EXISTS (SELECT 1 FROM archivos_compartidos ac WHERE ac.archivo_id = a.id) AS compartido,
                    EXISTS (SELECT 1 FROM enlaces_publicos e WHERE e.archivo_id = a.id AND e.activo = 1) AS enlace_publico
             FROM archivos a
             WHERE a.usuario_id = ? AND a.$filtroPadre AND a.papelera = 0
             ORDER BY a.nombre",
            [$usuarioId, ...$parametrosPadre]
        );

        return response()->json([
            'carpeta' => $carpeta,
            'ruta' => $ruta,
            'carpetas' => $carpetas,
            'archivos' => $archivos,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'carpeta_padre' => ['nullable', 'string'],
        ], $this->mensajes());

        $nombre = trim($datos['nombre']);
        $padreId = $datos['carpeta_padre'] ?? null;

        if ($nombre === '') {
            return $this->errorValidacion('nombre', 'El nombre es obligatorio.');
        }

        if ($padreId !== null && ! $this->carpetaActiva($padreId, $usuarioId)) {
            return $this->noEncontrado('La carpeta donde quieres crearla no existe.');
        }

        if ($this->nombreRepetido($nombre, $padreId, $usuarioId)) {
            return $this->errorValidacion('nombre', 'Ya existe una carpeta con ese nombre aquí.');
        }

        $id = (string) Str::uuid7();

        DB::insert(
            'INSERT INTO carpetas (id, usuario_id, carpeta_padre, nombre) VALUES (?, ?, ?, ?)',
            [$id, $usuarioId, $padreId, $nombre]
        );

        return response()->json(['carpeta' => $this->carpetaActiva($id, $usuarioId)], 201);
    }

    public function renombrar(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $carpeta = $this->carpetaActiva($id, $usuarioId);

        if (! $carpeta) {
            return $this->noEncontrado('La carpeta no existe.');
        }

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
        ], $this->mensajes());

        $nombre = trim($datos['nombre']);

        if ($nombre === '') {
            return $this->errorValidacion('nombre', 'El nombre es obligatorio.');
        }

        if ($this->nombreRepetido($nombre, $carpeta->carpeta_padre, $usuarioId, $id)) {
            return $this->errorValidacion('nombre', 'Ya existe una carpeta con ese nombre aquí.');
        }

        DB::update(
            'UPDATE carpetas SET nombre = ? WHERE id = ? AND usuario_id = ?',
            [$nombre, $id, $usuarioId]
        );

        return response()->json(['carpeta' => $this->carpetaActiva($id, $usuarioId)]);
    }

    /**
     * Mueve una carpeta (con todo su contenido) a otra carpeta o a la raíz.
     * Solo cambia su carpeta_padre: subcarpetas y archivos la siguen solos.
     */
    public function mover(Request $request, string $id): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);
        $carpeta = $this->carpetaActiva($id, $usuarioId);

        if (! $carpeta) {
            return $this->noEncontrado('La carpeta no existe.');
        }

        $request->validate([
            'carpeta_padre' => ['nullable', 'string'],
        ]);

        $destino = $request->input('carpeta_padre') ?: null;

        if ($destino !== null) {
            if (! $this->carpetaActiva($destino, $usuarioId)) {
                return $this->noEncontrado('La carpeta de destino no existe.');
            }

            // El destino no puede ser la carpeta misma ni estar dentro de ella:
            // se sube desde el destino hasta la raíz y se busca la carpeta en el camino.
            $camino = array_column($this->ruta($destino, $usuarioId), 'id');

            if (in_array($id, $camino, true)) {
                return $this->errorValidacion(
                    'carpeta_padre',
                    'No puedes mover una carpeta dentro de sí misma ni de una de sus subcarpetas.'
                );
            }
        }

        if ($this->nombreRepetido($carpeta->nombre, $destino, $usuarioId, $id)) {
            return $this->errorValidacion('carpeta_padre', 'Ya existe una carpeta con ese nombre en el destino.');
        }

        DB::update(
            'UPDATE carpetas SET carpeta_padre = ? WHERE id = ? AND usuario_id = ?',
            [$destino, $id, $usuarioId]
        );

        return response()->json([
            'carpeta' => ['id' => $id, 'carpeta_padre' => $destino],
            'origen' => $carpeta->carpeta_padre,
        ]);
    }

    /**
     * Carpetas desde la raíz hasta la carpeta indicada (para la barra de navegación).
     * Sube por carpeta_padre con una consulta recursiva.
     */
    private function ruta(string $carpetaId, int $usuarioId): array
    {
        return DB::select(
            'WITH RECURSIVE ruta AS (
                SELECT id, nombre, carpeta_padre, 0 AS nivel
                FROM carpetas
                WHERE id = ? AND usuario_id = ?
                UNION ALL
                SELECT c.id, c.nombre, c.carpeta_padre, r.nivel + 1
                FROM carpetas c
                JOIN ruta r ON c.id = r.carpeta_padre
            )
            SELECT id, nombre FROM ruta ORDER BY nivel DESC',
            [$carpetaId, $usuarioId]
        );
    }

    private function nombreRepetido(string $nombre, ?string $padreId, int $usuarioId, ?string $excluirId = null): bool
    {
        [$filtroPadre, $parametrosPadre] = $this->filtroPadre($padreId);

        $sql = "SELECT id FROM carpetas
                WHERE usuario_id = ? AND $filtroPadre AND papelera = 0 AND nombre = ?";
        $parametros = [$usuarioId, ...$parametrosPadre, $nombre];

        if ($excluirId !== null) {
            $sql .= ' AND id <> ?';
            $parametros[] = $excluirId;
        }

        return DB::selectOne($sql, $parametros) !== null;
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
        ];
    }
}
