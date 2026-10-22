<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ServerException;
use Throwable;

abstract class Controller
{
    /**
     * Base de MongoDB (se registra en AppServiceProvider).
     */
    public function __construct(protected Database $base) {}

    /**
     * Id del usuario con sesión iniciada. En la sesión se guarda como texto
     * y en las colecciones como ObjectId.
     */
    protected function usuarioId(Request $request): ObjectId
    {
        return new ObjectId($request->session()->get('usuario')['id']);
    }

    /**
     * Error de validación con el mismo formato que usa Laravel (422).
     */
    protected function errorValidacion(string $campo, string $mensaje): JsonResponse
    {
        return response()->json([
            'message' => $mensaje,
            'errors' => [$campo => [$mensaje]],
        ], 422);
    }

    protected function noEncontrado(string $mensaje): JsonResponse
    {
        return response()->json(['message' => $mensaje], 404);
    }

    /**
     * Carpeta activa (no en papelera) que pertenece al usuario, o null.
     * En MongoDB {carpeta_padre: null} sí encuentra la raíz: ya no hace falta
     * el "IS NULL" de SQL.
     */
    protected function carpetaActiva(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->carpetas->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => false],
            ['projection' => ['carpeta_padre' => 1, 'nombre' => 1, 'creado_en' => 1, 'actualizado_en' => 1]]
        );
    }

    protected function ahora(): UTCDateTime
    {
        return new UTCDateTime;
    }

    /**
     * Documento de MongoDB -> lo que espera el frontend: _id pasa a id,
     * las fechas a "Y-m-d H:i:s" (UTC, como las daba MySQL) y los ObjectId a texto.
     */
    protected function json(mixed $valor): mixed
    {
        if ($valor instanceof UTCDateTime) {
            return $valor->toDateTime()->format('Y-m-d H:i:s');
        }

        if ($valor instanceof ObjectId) {
            return (string) $valor;
        }

        if (is_array($valor)) {
            return array_map(fn ($elemento) => $this->json($elemento), $valor);
        }

        if (is_object($valor)) {
            $salida = [];

            foreach ((array) $valor as $campo => $dato) {
                $salida[$campo === '_id' ? 'id' : $campo] = $this->json($dato);
            }

            return $salida;
        }

        return $valor;
    }

    /**
     * Código de MongoDB cuando un índice único rechaza el documento.
     */
    protected function esDuplicado(Throwable $e): bool
    {
        if ($e instanceof BulkWriteException) {
            foreach ($e->getWriteResult()->getWriteErrors() as $error) {
                if ($error->getCode() === 11000) {
                    return true;
                }
            }
        }

        return $e instanceof ServerException && $e->getCode() === 11000;
    }
}
