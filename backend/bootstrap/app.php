<?php

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ForceJson;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Tenant first: the locale and every query below it depend on which
        // club the request belongs to.
        $middleware->api(prepend: [
            ForceJson::class,
            ResolveTenant::class,
            SetLocale::class,
        ]);

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'tenant' => ResolveTenant::class,
            'super-admin' => EnsureSuperAdmin::class,
        ]);

        // Keyed per signed in user, not per address: a club where reception,
        // the manager and three coaches share one office connection must not
        // have them throttling each other. Guests still fall back to the IP,
        // which is what protects the sign-in endpoints.
        $middleware->throttleApi('gymflow');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => __('auth.unauthenticated')], 401);
            }
        });

        // A path that matches no route never reaches the api middleware, so
        // the JSON header is not set yet and Laravel would answer with its
        // HTML error page. A client that mistypes an endpoint should still
        // get something it can parse.
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json(
                ['message' => $e->getMessage() ?: __('general.not_found')],
                $e->getStatusCode(),
                $e->getHeaders(),
            );
        });
    })->create();
