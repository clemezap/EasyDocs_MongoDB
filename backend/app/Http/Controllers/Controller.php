<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

abstract class Controller
{
    /**
     * Id del usuario con sesión iniciada.
     */
    protected function usuarioId(Request $request): int
    {
        return $request->session()->get('usuario')['id'];
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
     */
    protected function carpetaActiva(string $id, int $usuarioId): ?object
    {
        return DB::selectOne(
            'SELECT id, carpeta_padre, nombre, creado_en, actualizado_en
             FROM carpetas
             WHERE id = ? AND usuario_id = ? AND papelera = 0',
            [$id, $usuarioId]
        );
    }

    /**
     * En SQL "= NULL" nunca es verdadero, por eso la raíz se filtra con IS NULL.
     * Devuelve [condición, parámetros].
     */
    protected function filtroPadre(?string $padreId): array
    {
        return $padreId === null
            ? ['carpeta_padre IS NULL', []]
            : ['carpeta_padre = ?', [$padreId]];
    }
}
