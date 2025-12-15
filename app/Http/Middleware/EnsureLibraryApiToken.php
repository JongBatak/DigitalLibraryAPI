<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLibraryApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.library.api_token');

        if ($expected === '') {
            return $next($request);
        }

        $provided = $request->header('X-Api-Key')
            ?? $request->bearerToken()
            ?? $request->query('api_token');

        if (! is_string($provided) || $provided === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Unauthorized: missing or invalid API token.');
        }

        return $next($request);
    }
}
