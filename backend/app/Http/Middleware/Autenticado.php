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
        if (! $request->session()->has('usuario')) {
            return response()->json(['message' => 'No has iniciado sesión.'], 401);
        }

        return $next($request);
    }
}
