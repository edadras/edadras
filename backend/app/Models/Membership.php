<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A purchased membership. Both limits apply at once: a 30 session pass that
 * also runs out after 30 days expires on whichever comes first.
 */
class Membership extends Model
{
    use BelongsToTenant, HasFactory;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const FROZEN = 'frozen';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'member_id', 'membership_plan_id', 'type', 'starts_at', 'ends_at',
        'total_sessions', 'remaining_sessions', 'price', 'discount', 'paid_amount',
        'status', 'frozen_from', 'frozen_until', 'created_by', 'notes',
    ];

    protected $attributes = [
        'status' => self::ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'frozen_from' => 'date',
            'frozen_until' => 'date',
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function invoiceItems(): MorphMany
    {
        return $this->morphMany(InvoiceItem::class, 'itemable');
    }

    public function isExpiredByDate(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isBefore(today());
    }

    public function isOutOfSessions(): bool
    {
        return $this->remaining_sessions !== null && $this->remaining_sessions <= 0;
    }

    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE
            && ! $this->isExpiredByDate()
            && ! $this->isOutOfSessions()
            && ! $this->starts_at->isAfter(today());
    }

    /** Days left, null for open ended memberships. */
    public function daysRemaining(): ?int
    {
        if ($this->ends_at === null) {
            return null;
        }

        return max(0, today()->diffInDays($this->ends_at, false));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [today(), today()->addDays($days)]);
    }
}
