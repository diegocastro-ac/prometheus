<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persiste el idioma activo del usuario en su ficha. El selector de
 * idioma de Filament guarda la eleccion en sesion/COOKIE, que el comando
 * del briefing diario (corre sin request) no puede ver — con esta
 * columna el job puede redactar el resumen en el idioma del usuario.
 */
class SyncPreferredLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user !== null && $user->preferred_locale !== app()->getLocale()) {
            $user->preferred_locale = app()->getLocale();
            $user->saveQuietly();
        }

        return $response;
    }
}
