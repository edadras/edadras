<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A club: gym, pool, martial arts hall, yoga or pilates studio.
 * Everything a club owns hangs off this record.
 */
class Tenant extends Model
{
    use HasFactory, RecordsAudit, SoftDeletes;

    public const TYPES = [
        'gym', 'pool', 'martial_arts', 'yoga', 'pilates', 'crossfit', 'football', 'multi',
    ];

    protected $fillable = [
        'slug', 'name', 'type', 'logo_path', 'brand_color', 'phone', 'email',
        'address', 'city', 'country', 'socials', 'working_hours', 'rules',
        'timezone', 'locale', 'currency', 'status', 'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'socials' => 'array',
            'working_hours' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    public function coaches(): HasMany
    {
        return $this->hasMany(Coach::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->latestOfMany();
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
