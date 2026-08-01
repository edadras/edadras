<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToTenant, HasFactory, RecordsAudit;

    protected $fillable = [
        'tenant_id', 'member_id', 'number', 'subtotal', 'discount', 'tax', 'total',
        'paid_amount', 'status', 'issued_at', 'due_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function balance(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }

    /** Recomputes totals from the line items and the recorded payments. */
    public function recalculate(): static
    {
        $this->loadMissing('items');

        $this->subtotal = $this->items->sum(fn (InvoiceItem $item) => (float) $item->quantity * (float) $item->unit_price);
        $this->discount = $this->items->sum('discount');
        $this->total = max(0, $this->subtotal - $this->discount + (float) $this->tax);
        $this->paid_amount = $this->payments()->where('status', 'paid')->sum('amount');

        $this->status = match (true) {
            $this->status === 'cancelled' => 'cancelled',
            $this->paid_amount <= 0 => 'unpaid',
            $this->paid_amount < $this->total => 'partial',
            default => 'paid',
        };

        return $this;
    }
}
