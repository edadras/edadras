<?php

namespace App\Providers;

use App\Models\Role;
use App\Notifications\Channels\GymFlowPushChannel;
use App\Services\Ai\ClaudeClient;
use App\Services\DatabaseTranslationLoader;
use App\Support\Auditor;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class GymFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(Auditor::class);

        $this->app->singleton(ClaudeClient::class, fn ($app) => new ClaudeClient(
            $app['config']->get('gymflow.ai', []),
        ));

        // Wrap the file loader so every string can be overridden from the
        // translations table, globally or per club.
        $this->app->extend('translation.loader', fn ($loader, $app) => new DatabaseTranslationLoader(
            $loader,
            $app->make(TenantContext::class),
        ));
    }

    public function boot(): void
    {
        // Every permission in the catalogue becomes a gate, so controllers and
        // policies can just call $this->authorize('members.create').
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn ($user) => $user->hasPermission($permission));
        }

        Gate::before(fn ($user) => $user->isSuperAdmin() ? true : null);

        Gate::define('owner-only', fn ($user) => $user->hasRole(Role::OWNER));

        // Lets a notification list `push` next to `database`.
        Notification::extend('push', fn ($app) => $app->make(GymFlowPushChannel::class));

        RateLimiter::for('gymflow', fn (Request $request) => $request->user()
            ? Limit::perMinute((int) config('gymflow.rate_limit.per_user', 300))->by('user:'.$request->user()->id)
            : Limit::perMinute((int) config('gymflow.rate_limit.per_ip', 60))->by($request->ip()));
    }
}
