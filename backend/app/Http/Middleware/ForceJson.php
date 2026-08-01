<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything under /api answers in JSON, whatever the client asked for.
 *
 * Without this, a client that forgets the Accept header gets Laravel's web
 * behaviour on a validation failure: a 302 redirect to an HTML page. A phone
 * on a bad password should see a 422 it can read, not a redirect.
 */
class ForceJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
