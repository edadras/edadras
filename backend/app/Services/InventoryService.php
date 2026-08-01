<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Stock in / out with a movement trail behind every change. */
class InventoryService
{
    public function receive(Product $product, int $quantity, ?string $reason = null, ?Model $reference = null): StockMovement
    {
        return $this->move($product, abs($quantity), 'in', $reason ?? 'purchase', $reference);
    }

    public function release(Product $product, int $quantity, ?string $reason = null, ?Model $reference = null): StockMovement
    {
        if ($product->stock < $quantity) {
            throw new RuntimeException("Not enough stock for product {$product->id}.");
        }

        return $this->move($product, -abs($quantity), 'out', $reason ?? 'sale', $reference);
    }

    /** Sets the counted quantity after a stock take. */
    public function adjust(Product $product, int $countedStock, ?string $reason = null): StockMovement
    {
        return $this->move($product, $countedStock - $product->stock, 'adjust', $reason ?? 'stock_take');
    }

    protected function move(Product $product, int $delta, string $type, string $reason, ?Model $reference = null): StockMovement
    {
        return DB::transaction(function () use ($product, $delta, $type, $reason, $reference) {
            $product->increment('stock', $delta);
            $product->refresh();

            return StockMovement::create([
                'product_id' => $product->id,
                'type' => $type,
                'quantity' => $delta,
                'stock_after' => $product->stock,
                'reason' => $reason,
                'reference_type' => $reference ? $reference::class : null,
                'reference_id' => $reference?->getKey(),
                'user_id' => auth()->id(),
            ]);
        });
    }
}
