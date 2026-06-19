<?php

namespace Packages\Commander\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El panel del operador SOLO acepta usuarios de operador (package_id NULL). Los tokens Sanctum
 * son globales, asi que sin esto un admin de tenant (package_id != NULL) podria reutilizar su
 * token en commander.{BASE_DOMAIN} y, con los roles Spatie compartidos, controlar la plataforma.
 */
class CommanderOperatorMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->package_id !== null) {
            abort(403, 'Operator panel: tenant credentials are not allowed.');
        }

        return $next($request);
    }
}
