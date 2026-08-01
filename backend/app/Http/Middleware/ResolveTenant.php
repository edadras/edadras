<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides which club the request belongs to. An authenticated user carries
 * their tenant with them; public endpoints (the club's public page, signup)
 * name it with an X-Tenant header or a subdomain.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id) {
            $this->tenancy->set($user->tenant);
        } elseif ($slug = $this->slugFromRequest($request)) {
            $tenant = Tenant::where('slug', $slug)->first();

            if (! $tenant) {
                return response()->json(['message' => __('general.club_not_found')], 404);
            }

            $this->tenancy->set($tenant);
        }

        $tenant = $this->tenancy->get();

        // A suspended club keeps its data but stops serving its apps. Super
        // admins still get through so they can fix the subscription.
        if ($tenant && ! $tenant->isActive() && ! $user?->isSuperAdmin()) {
            return response()->json([
                'message' => __('general.club_suspended'),
                'status' => $tenant->status,
            ], 403);
        }

        $response = $next($request);

        $this->tenancy->forget();

        return $response;
    }

    protected function slugFromRequest(Request $request): ?string
    {
        if ($header = $request->header('X-Tenant')) {
            return $header;
        }

        if ($request->route('tenant')) {
            return (string) $request->route('tenant');
        }

        $host = $request->getHost();
        $base = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($base && str_ends_with($host, ".{$base}")) {
            return substr($host, 0, -strlen(".{$base}"));
        }

        return null;
    }
}
