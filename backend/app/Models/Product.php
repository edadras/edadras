<?php

namespace App\Models;

use App\Models\Concerns\RecordsAudit;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Shop item: supplements, clothing, drinks, equipment. */
class Product extends Model
{
    use BelongsToTenant, HasFactory, RecordsAudit, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'sku', 'category', 'description', 'price', 'cost',
        'stock', 'min_stock', 'unit', 'image_path', 'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
        'stock' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '<=', 'min_stock');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
