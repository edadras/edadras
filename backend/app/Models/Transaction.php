<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A single line of the daily cash box, income or expense. */
class Transaction extends Model
{
    use BelongsToTenant, HasFactory, RecordsAudit;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    protected $fillable = [
        'tenant_id', 'type', 'category', 'amount', 'method', 'description',
        'reference_type', 'reference_id', 'member_id', 'user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeIncome(Builder $query): Builder
    {
        return $query->where('type', self::INCOME);
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('type', self::EXPENSE);
    }

    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }
}
