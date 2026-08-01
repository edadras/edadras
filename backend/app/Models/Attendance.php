<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One gate pass: check-in, and the matching check-out when it happens. */
class Attendance extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'member_id', 'membership_id', 'checked_in_at', 'checked_out_at',
        'method', 'device', 'staff_id', 'consumed_session', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'consumed_session' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function isInside(): bool
    {
        return $this->checked_out_at === null;
    }

    public function durationMinutes(): ?int
    {
        return $this->checked_out_at
            ? (int) $this->checked_in_at->diffInMinutes($this->checked_out_at)
            : null;
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('checked_in_at', today());
    }

    public function scopeStillInside(Builder $query): Builder
    {
        return $query->whereNull('checked_out_at');
    }
}
