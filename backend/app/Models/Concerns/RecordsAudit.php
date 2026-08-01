<?php

namespace App\Models\Concerns;

use App\Support\Auditor;

/**
 * Put this on anything a manager would later ask "who changed this?" about.
 * Money, memberships, people and permissions all carry it.
 */
trait RecordsAudit
{
    public static function bootRecordsAudit(): void
    {
        static::created(fn ($model) => app(Auditor::class)->created($model));
        static::updated(fn ($model) => app(Auditor::class)->updated($model));
        static::deleted(fn ($model) => app(Auditor::class)->deleted($model));
    }
}
