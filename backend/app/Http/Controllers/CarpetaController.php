<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;
use Throwable;

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

        $filtro = ['usuario_id' => $usuarioId, 'carpeta_padre' => $carpetaId, 'papelera' => false];

        // El orden por nombre usa la intercalación de la colección (sin distinguir mayúsculas)
        $carpetas = $this->base->carpetas->find($filtro, [
            'projection' => ['nombre' => 1, 'creado_en' => 1, 'actualizado_en' => 1],
            'sort' => ['nombre' => 1],
        ]);

        // compartido y enlace_publico salen del mismo documento: antes eran dos
        // subconsultas EXISTS sobre otras tablas
        $archivos = $this->base->archivos->aggregate([
            ['$match' => $filtro],
            ['$sort' => ['nombre' => 1]],
            ['$project' => [
                'nombre' => 1,
                'tipo_mime' => 1,
                'tamano' => 1,
                'creado_en' => 1,
                'actualizado_en' => 1,
                'compartido' => ['$gt' => [['$size' => '$compartidos'], 0]],
                'enlace_publico' => ['$in' => [true, '$enlaces_publicos.activo']],
            ]],
        ]);

        return response()->json([
            'carpeta' => $this->json($carpeta),
            'ruta' => $ruta,
            'carpetas' => $this->json($carpetas->toArray()),
            'archivos' => $this->json($archivos->toArray()),
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
        $ahora = $this->ahora();

        // El índice único uq_carpetas_nombre_activo cubre dos peticiones al mismo tiempo
        try {
            $this->base->carpetas->insertOne([
                '_id' => $id,
                'usuario_id' => $usuarioId,
                'carpeta_padre' => $padreId,
                'nombre' => $nombre,
                'papelera' => false,
                'fecha_papelera' => null,
                'creado_en' => $ahora,
                'actualizado_en' => $ahora,
            ]);
        } catch (Throwable $e) {
            if ($this->esDuplicado($e)) {
                return $this->errorValidacion('nombre', 'Ya existe una carpeta con ese nombre aquí.');
            }

            throw $e;
        }

        return response()->json(['carpeta' => $this->json($this->carpetaActiva($id, $usuarioId))], 201);
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

        try {
            $this->base->carpetas->updateOne(
                ['_id' => $id, 'usuario_id' => $usuarioId],
                ['$set' => ['nombre' => $nombre, 'actualizado_en' => $this->ahora()]]
            );
        } catch (Throwable $e) {
            if ($this->esDuplicado($e)) {
                return $this->errorValidacion('nombre', 'Ya existe una carpeta con ese nombre aquí.');
            }

            throw $e;
        }

        return response()->json(['carpeta' => $this->json($this->carpetaActiva($id, $usuarioId))]);
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

        try {
            $this->base->carpetas->updateOne(
                ['_id' => $id, 'usuario_id' => $usuarioId],
                ['$set' => ['carpeta_padre' => $destino, 'actualizado_en' => $this->ahora()]]
            );
        } catch (Throwable $e) {
            if ($this->esDuplicado($e)) {
                return $this->errorValidacion('carpeta_padre', 'Ya existe una carpeta con ese nombre en el destino.');
            }

            throw $e;
        }

        return response()->json([
            'carpeta' => ['id' => $id, 'carpeta_padre' => $destino],
            'origen' => $carpeta->carpeta_padre,
        ]);
    }

    /**
     * Carpetas desde la raíz hasta la carpeta indicada (para la barra de navegación).
     * $graphLookup sube por carpeta_padre: es el equivalente del WITH RECURSIVE de MySQL.
     * depthField numera a los ancestros (0 = el padre directo).
     */
    private function ruta(string $carpetaId, ObjectId $usuarioId): array
    {
        $resultado = $this->base->carpetas->aggregate([
            ['$match' => ['_id' => $carpetaId, 'usuario_id' => $usuarioId]],
            ['$graphLookup' => [
                'from' => 'carpetas',
                'startWith' => '$carpeta_padre',
                'connectFromField' => 'carpeta_padre',
                'connectToField' => '_id',
                'as' => 'ancestros',
                'depthField' => 'nivel',
            ]],
        ])->toArray();

        if ($resultado === []) {
            return [];
        }

        $carpeta = $resultado[0];
        $ancestros = $carpeta->ancestros;

        // Del más lejano (la carpeta en la raíz) al padre directo
        usort($ancestros, fn ($a, $b) => $b->nivel <=> $a->nivel);

        return array_map(
            fn ($c) => ['id' => $c->_id, 'nombre' => $c->nombre],
            [...$ancestros, $carpeta]
        );
    }

    private function nombreRepetido(string $nombre, ?string $padreId, ObjectId $usuarioId, ?string $excluirId = null): bool
    {
        // La intercalación de la colección hace que "Tareas" y "tareas" coincidan, como en MySQL
        $filtro = [
            'usuario_id' => $usuarioId,
            'carpeta_padre' => $padreId,
            'papelera' => false,
            'nombre' => $nombre,
        ];

        if ($excluirId !== null) {
            $filtro['_id'] = ['$ne' => $excluirId];
        }

        return $this->base->carpetas->findOne($filtro, ['projection' => ['_id' => 1]]) !== null;
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede tener más de 255 caracteres.',
        ];
    }
}
