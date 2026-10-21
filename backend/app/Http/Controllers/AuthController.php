<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

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

        $existe = DB::selectOne('SELECT id FROM usuarios WHERE correo = ?', [$correo]);

        if ($existe) {
            return $this->errorValidacion('correo', 'Ya existe una cuenta con este correo.');
        }

        DB::insert(
            'INSERT INTO usuarios (nombre, correo, password) VALUES (?, ?, ?)',
            [trim($datos['nombre']), $correo, Hash::make($datos['password'])]
        );

        $usuario = [
            'id' => (int) DB::getPdo()->lastInsertId(),
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

        $fila = DB::selectOne(
            'SELECT id, nombre, correo, password FROM usuarios WHERE correo = ?',
            [mb_strtolower(trim($datos['correo']))]
        );

        if (! $fila || ! Hash::check($datos['password'], $fila->password)) {
            return $this->errorValidacion('correo', 'Correo o contraseña incorrectos.');
        }

        $usuario = [
            'id' => (int) $fila->id,
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
