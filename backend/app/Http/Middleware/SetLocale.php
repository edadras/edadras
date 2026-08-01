<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language for the response: what the app asked for, else the
 * user's own preference, else the club default.
 */
class SetLocale
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('gymflow.locales'));

        $locale = collect([
            $request->header('X-Locale'),
            $request->query('locale'),
            $request->user()?->locale,
            $this->tenancy->get()?->locale,
            config('gymflow.default_locale'),
        ])->first(fn ($candidate) => $candidate && in_array($candidate, $supported, true));

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
