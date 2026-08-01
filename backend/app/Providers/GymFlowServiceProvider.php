<?php

namespace App\Providers;

use App\Models\Role;
use App\Services\Ai\ClaudeClient;
use App\Services\DatabaseTranslationLoader;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class GymFlowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

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
    }
}
