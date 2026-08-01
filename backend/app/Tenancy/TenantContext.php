<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Holds the tenant for the current request / job. Everything else in the
 * application reads the active club from here instead of passing it around.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Run a callback as another tenant and restore the previous one after,
     * used by queued jobs, reports and the Super Admin panel.
     */
    public function run(?Tenant $tenant, callable $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
