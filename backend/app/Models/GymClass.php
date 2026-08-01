<?php

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pilates, Yoga, TRX, bodybuilding, or a pool lane sold per "sans". */
class GymClass extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations;

    protected $table = 'gym_classes';

    protected $fillable = [
        'tenant_id', 'coach_id', 'name', 'description', 'kind', 'capacity',
        'duration_minutes', 'price', 'gender', 'color', 'is_active',
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

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePool(Builder $query): Builder
    {
        return $query->where('kind', 'pool');
    }
}
