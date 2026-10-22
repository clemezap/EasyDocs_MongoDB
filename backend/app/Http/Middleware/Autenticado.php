<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Autenticado
{
    /**
     * Rechaza la petición si no hay un usuario en la sesión.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('usuario')['id'] ?? null;

        // Sesiones de la versión con MySQL guardaban un id numérico: ya no sirven
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{24}$/', $id)) {
            $request->session()->forget('usuario');

            return response()->json(['message' => 'No has iniciado sesión.'], 401);
        }

        return $next($request);
    }
}
