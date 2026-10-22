<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use MongoDB\BSON\ObjectId;

/**
 * Compartición privada de archivos entre usuarios registrados.
 *
 * La relación muchos a muchos entre archivos y usuarios (antes la tabla
 * archivos_compartidos) vive ahora en el arreglo "compartidos" de cada archivo:
 * un archivo tiene un solo dueño (usuario_id), pero puede ser accesible por varios
 * usuarios con permiso de lectura (descargar) o escritura (descargar y cambiar el
 * nombre). Solo el dueño administra con quién se comparte.
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

        $destinatario = $this->base->usuarios->findOne(
            ['correo' => mb_strtolower(trim($datos['correo']))],
            ['projection' => ['nombre' => 1, 'correo' => 1]]
        );

        if (! $destinatario) {
            return $this->errorValidacion('correo', 'No existe una cuenta con ese correo.');
        }

        if ((string) $destinatario->_id === (string) $usuarioId) {
            return $this->errorValidacion('correo', 'Eres el propietario de este archivo.');
        }

        // El filtro $ne hace el papel del UNIQUE (archivo_id, usuario_id): si la persona
        // ya está en el arreglo, el documento no coincide y no se agrega dos veces.
        $resultado = $this->base->archivos->updateOne(
            ['_id' => $archivoId, 'compartidos.usuario_id' => ['$ne' => $destinatario->_id]],
            ['$push' => ['compartidos' => [
                'id' => (string) Str::uuid7(),
                'usuario_id' => $destinatario->_id,
                // Copia del nombre y correo para listar sin $lookup (el perfil no se edita)
                'nombre' => $destinatario->nombre,
                'correo' => $destinatario->correo,
                'permiso' => $datos['permiso'],
                'creado_en' => $this->ahora(),
            ]]]
        );

        if ($resultado->getMatchedCount() === 0) {
            return $this->errorValidacion('correo', 'Esta persona ya tiene acceso.');
        }

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

        // "$" es el elemento del arreglo que coincidió con compartidos.id
        $this->base->archivos->updateOne(
            ['_id' => $archivoId, 'compartidos.id' => $compartidoId],
            ['$set' => ['compartidos.$.permiso' => $datos['permiso']]]
        );

        return response()->json(['compartidos' => $this->compartidosDe($archivoId)]);
    }

    /**
     * Quitar el acceso (revocar). Se saca el elemento del arreglo: el archivo desaparece
     * de "Compartidos conmigo" de esa persona y ya no puede descargarlo.
     */
    public function quitar(Request $request, string $archivoId, string $compartidoId): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        if (! $this->archivoPropio($archivoId, $usuarioId)) {
            return $this->noEncontrado('El archivo no existe.');
        }

        $this->base->archivos->updateOne(
            ['_id' => $archivoId],
            ['$pull' => ['compartidos' => ['id' => $compartidoId]]]
        );

        return response()->json(['compartidos' => $this->compartidosDe($archivoId)]);
    }

    /**
     * "Compartidos conmigo": archivos de otros usuarios a los que tengo acceso.
     * Si el dueño manda el archivo a la papelera, deja de aparecer aquí.
     */
    public function conmigo(Request $request): JsonResponse
    {
        $usuarioId = $this->usuarioId($request);

        $archivos = $this->base->archivos->aggregate([
            ['$match' => ['compartidos.usuario_id' => $usuarioId, 'papelera' => false]],
            // Mi elemento dentro de "compartidos" (de ahí salen el permiso y la fecha)
            ['$set' => ['acceso' => ['$first' => ['$filter' => [
                'input' => '$compartidos',
                'cond' => ['$eq' => ['$$this.usuario_id', $usuarioId]],
            ]]]]],
            // El dueño sí se referencia: su nombre se busca en usuarios
            ['$lookup' => [
                'from' => 'usuarios',
                'localField' => 'usuario_id',
                'foreignField' => '_id',
                'as' => 'propietario',
            ]],
            ['$unwind' => '$propietario'],
            ['$sort' => ['acceso.creado_en' => -1]],
            ['$project' => [
                'nombre' => 1,
                'tipo_mime' => 1,
                'tamano' => 1,
                'propietario' => '$propietario.nombre',
                'propietario_correo' => '$propietario.correo',
                'permiso' => '$acceso.permiso',
                'compartido_en' => '$acceso.creado_en',
            ]],
        ]);

        return response()->json(['archivos' => $this->json($archivos->toArray())]);
    }

    private function compartidosDe(string $archivoId): array
    {
        $archivo = $this->base->archivos->findOne(
            ['_id' => $archivoId],
            ['projection' => ['compartidos' => 1]]
        );

        // $push agrega al final: el arreglo ya está en orden de creación
        return array_map(fn ($c) => [
            'id' => $c->id,
            'permiso' => $c->permiso,
            'nombre' => $c->nombre,
            'correo' => $c->correo,
        ], $archivo->compartidos ?? []);
    }

    private function archivoPropio(string $id, ObjectId $usuarioId): ?object
    {
        return $this->base->archivos->findOne(
            ['_id' => $id, 'usuario_id' => $usuarioId, 'papelera' => false],
            ['projection' => ['_id' => 1]]
        );
    }
}
