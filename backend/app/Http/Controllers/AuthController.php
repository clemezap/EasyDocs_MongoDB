<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

class AuthController extends Controller
{
    public function registro(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'correo' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.max' => 'El nombre no puede tener más de 150 caracteres.',
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'El correo no es válido.',
            'correo.max' => 'El correo no puede tener más de 255 caracteres.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $correo = mb_strtolower(trim($datos['correo']));

        if ($this->base->usuarios->findOne(['correo' => $correo], ['projection' => ['_id' => 1]])) {
            return $this->errorValidacion('correo', 'Ya existe una cuenta con este correo.');
        }

        // El índice único uq_usuarios_correo cubre el caso de dos registros al mismo tiempo
        try {
            $resultado = $this->base->usuarios->insertOne([
                'nombre' => trim($datos['nombre']),
                'correo' => $correo,
                'password' => Hash::make($datos['password']),
                'creado_en' => $this->ahora(),
            ]);
        } catch (Throwable $e) {
            if ($this->esDuplicado($e)) {
                return $this->errorValidacion('correo', 'Ya existe una cuenta con este correo.');
            }

            throw $e;
        }

        $usuario = [
            'id' => (string) $resultado->getInsertedId(),
            'nombre' => trim($datos['nombre']),
            'correo' => $correo,
        ];

        $this->iniciarSesion($request, $usuario);

        return response()->json(['usuario' => $usuario], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'correo' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'correo.required' => 'El correo es obligatorio.',
            'correo.email' => 'El correo no es válido.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        $fila = $this->base->usuarios->findOne(['correo' => mb_strtolower(trim($datos['correo']))]);

        if (! $fila || ! Hash::check($datos['password'], $fila->password)) {
            return $this->errorValidacion('correo', 'Correo o contraseña incorrectos.');
        }

        $usuario = [
            'id' => (string) $fila->_id,
            'nombre' => $fila->nombre,
            'correo' => $fila->correo,
        ];

        $this->iniciarSesion($request, $usuario);

        return response()->json(['usuario' => $usuario]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesión cerrada.']);
    }

    /**
     * Devuelve el usuario de la sesión actual, o null si no hay sesión.
     */
    public function sesion(Request $request): JsonResponse
    {
        return response()->json(['usuario' => $request->session()->get('usuario')]);
    }

    private function iniciarSesion(Request $request, array $usuario): void
    {
        // Nuevo id de sesión para evitar fijación de sesión
        $request->session()->regenerate();
        $request->session()->put('usuario', $usuario);
    }
}
