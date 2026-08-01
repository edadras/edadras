<?php

namespace App\Services;

use App\Models\Translation;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Loads every user facing string from the translations table, falling back to
 * the files that ship with the app. A club can override any string for itself
 * without touching the global set.
 */
class DatabaseTranslationLoader implements Loader
{
    public function __construct(
        protected Loader $fallback,
        protected TenantContext $tenancy,
    ) {}

    public function load($locale, $group, $namespace = null): array
    {
        $lines = $this->fallback->load($locale, $group, $namespace);

        if ($namespace !== null && $namespace !== '*') {
            return $lines;
        }

        return array_replace_recursive($lines, $this->fromDatabase($locale, $group));
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->fallback->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->fallback->addJsonPath($path);
    }

    public function namespaces(): array
    {
        return $this->fallback->namespaces();
    }

    /** @return array<string, string> */
    protected function fromDatabase(string $locale, string $group): array
    {
        $tenantId = $this->tenancy->id();

        try {
            if (! Schema::hasTable('translations')) {
                return [];
            }

            $global = $this->cached($locale, $group, null);
            $scoped = $tenantId ? $this->cached($locale, $group, $tenantId) : [];

            return array_replace($global, $scoped);
        } catch (Throwable) {
            // Before the first migration there is nothing to read, and a
            // missing table must never break the console.
            return [];
        }
    }

    /** @return array<string, string> */
    protected function cached(string $locale, string $group, ?int $tenantId): array
    {
        $key = "translations:{$locale}:{$group}:".($tenantId ?? 'global');

        return Cache::remember($key, now()->addHour(), fn () => Translation::query()
            ->where('locale', $locale)
            ->where('group', $group)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'))
            ->pluck('value', 'key')
            ->all());
    }

    public static function flush(): void
    {
        foreach (array_keys(config('gymflow.locales')) as $locale) {
            foreach (['general', 'checkin', 'ai', 'validation', 'auth'] as $group) {
                Cache::forget("translations:{$locale}:{$group}:global");
            }
        }
    }
}
