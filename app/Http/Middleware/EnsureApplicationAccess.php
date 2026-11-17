<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApplicationAccess
{
    public function handle(Request $request, Closure $next, string $application): Response
    {
        $user = $request->user();
        abort_unless($user && $user->status === 'active' && ! $user->locked_at, 403);

        if ($application === 'prestamos' && ! $user->can('prestamos.access') && $request->routeIs('dashboard') && $user->can('cajas.access')) {
            return redirect()->route('cajas.home');
        }
        abort_unless($user->can($application.'.access'), 403);

        return $next($request);
    }
}
