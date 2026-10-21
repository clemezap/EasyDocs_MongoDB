<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compartición privada de archivos entre usuarios registrados.
 *
 * archivos_compartidos es la relación muchos a muchos entre archivos y usuarios:
 * un archivo tiene un solo dueño (archivos.usuario_id), pero puede ser accesible
 * por varios usuarios con permiso de lectura (descargar) o escritura (descargar y
 * cambiar el nombre). Solo el dueño administra con quién se comparte.
 */
class CompartirController extends Controller
{
    private const PERMISOS = ['lectura', 'escritura'];

    /**
     * Personas con acceso a un archivo propio.
     */
    public function listar(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        return response()->json(['compartidos' => $this->compartidosDe($archivoId)]);
    }

    public function agregar(Request $request, string $archivoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        $datos = $request->validate([
            'correo' => ['required', 'email'],
            'permiso' => ['required', 'in:'.implode(',', self::PERMISOS)],
        ], [
            'correo.required' => 'Escribe el correo de la persona.',
            'correo.email' => 'El correo no es válido.',
            'permiso.in' => 'El permiso no es válido.',
        ]);

        $destinatario = DB::selectOne(
            'SELECT id FROM usuarios WHERE correo = ?',
            [mb_strtolower(trim($datos['correo']))]
        );

        if (! $destinatario) {
            return $this->errorValidacion('correo', 'No existe una cuenta con ese correo.');
        }

        if ((int) $destinatario->id === $usuarioId) {
            return $this->errorValidacion('correo', 'Eres el propietario de este archivo.');
        }

        $existente = DB::selectOne(
            'SELECT id FROM archivos_compartidos WHERE archivo_id = ? AND usuario_id = ?',
            [$archivoId, $destinatario->id]
        );

        if ($existente) {
            return $this->errorValidacion('correo', 'Esta persona ya tiene acceso.');
        }

        DB::insert(
            'INSERT INTO archivos_compartidos (id, archivo_id, usuario_id, permiso) VALUES (?, ?, ?, ?)',
            [(string) Str::uuid7(), $archivoId, $destinatario->id, $datos['permiso']]
        );

        return response()->json(['compartidos' => $this->compartidosDe($archivoId)], 201);
    }

    public function cambiarPermiso(Request $request, string $archivoId, string $compartidoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        $datos = $request->validate([
            'permiso' => ['required', 'in:'.implode(',', self::PERMISOS)],
        ], [
            'permiso.in' => 'El permiso no es válido.',
        ]);

        DB::update(
            'UPDATE archivos_compartidos SET permiso = ? WHERE id = ? AND archivo_id = ?',
            [$datos['permiso'], $compartidoId, $archivoId]
        );

        return response()->json(['compartidos' => $this->compartidosDe($archivoId)]);
    }

    /**
     * Quitar el acceso (revocar). Se borra la fila: el archivo desaparece de
     * "Compartidos conmigo" de esa persona y ya no puede descargarlo.
     */
    public function quitar(Request $request, string $archivoId, string $compartidoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        DB::delete(
            'DELETE FROM archivos_compartidos WHERE id = ? AND archivo_id = ?',
            [$compartidoId, $archivoId]
        );

        return response()->json(['compartidos' => $this->compartidosDe($archivoId)]);
    }

    /**
     * "Compartidos conmigo": archivos de otros usuarios a los que tengo acceso.
     * Si el dueño manda el archivo a la papelera, deja de aparecer aquí.
     */
    public function conmigo(Request $request): JsonResponse
    {
        $archivos = DB::select(
            'SELECT a.id, a.nombre, a.tipo_mime, a.tamano,
                    u.nombre AS propietario, u.correo AS propietario_correo,
                    ac.permiso, ac.creado_en AS compartido_en
             FROM archivos_compartidos ac
             JOIN archivos a ON a.id = ac.archivo_id
             JOIN usuarios u ON u.id = a.usuario_id
             WHERE ac.usuario_id = ? AND a.papelera = 0
             ORDER BY ac.creado_en DESC',
            [$this->usuarioId($request)]
        );

        return response()->json(['archivos' => $archivos]);
    }

    private function compartidosDe(string $archivoId): array
    {
        return DB::select(
            'SELECT ac.id, ac.permiso, u.nombre, u.correo
             FROM archivos_compartidos ac
             JOIN usuarios u ON u.id = ac.usuario_id
             WHERE ac.archivo_id = ?
             ORDER BY ac.creado_en',
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
