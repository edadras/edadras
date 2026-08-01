<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Models\Concerns\RecordsAudit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable membership: 1/3/6/12 months, 10/20/50 sessions, or unlimited.
 */
class MembershipPlan extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, RecordsAudit;

    public const TYPE_DURATION = 'duration';

    public const TYPE_SESSION = 'session';

    public const TYPE_UNLIMITED = 'unlimited';

    protected $fillable = [
        'tenant_id', 'name', 'description', 'type', 'duration_days', 'session_count',
        'price', 'freeze_days', 'color', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function tracksSessions(): bool
    {
        return $this->type === self::TYPE_SESSION;
    }
}
