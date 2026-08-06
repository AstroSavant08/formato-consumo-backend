<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): Response  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $user->loadMissing('role');
        $roleName = $user->role?->nombre;

        if ($roleName === null || ! in_array($roleName, $roles, true)) {
            return response()->json(['message' => 'No tiene permiso para esta acción.'], 403);
        }

        return $next($request);
    }
}
