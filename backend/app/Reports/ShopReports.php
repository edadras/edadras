<?php

namespace App\Reports;

use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Reports\Concerns\AggregatesRows;
use Illuminate\Support\Facades\DB;

/** The shop counter: what sells, what sits there and what it is all worth. */
class ShopReports
{
    use AggregatesRows;

    public function catalogue(): array
    {
        return Product::withTrashed()
            ->get()
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category,
                'price' => (float) $p->price,
                'cost' => (float) $p->cost,
                'margin' => round((float) $p->price - (float) $p->cost, 2),
                'stock' => (float) $p->stock,
                'min_stock' => (float) $p->min_stock,
                'active' => (bool) $p->is_active,
            ])
            ->all();
    }

    public function byCategory(): array
    {
        return $this->breakdown(Product::query(), 'category');
    }

    public function lowStock(): array
    {
        return Product::whereColumn('stock', '<=', 'min_stock')
            ->orderBy('stock')
            ->get()
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category,
                'stock' => (float) $p->stock,
                'min_stock' => (float) $p->min_stock,
                'shortfall' => round((float) $p->min_stock - (float) $p->stock, 2),
            ])
            ->all();
    }

    public function inventoryValuation(): array
    {
        $products = Product::where('stock', '>', 0)->get();

        return [
            'lines' => $products->count(),
            'units' => round((float) $products->sum('stock'), 2),
            'at_cost' => round((float) $products->sum(fn (Product $p) => (float) $p->cost * (float) $p->stock), 2),
            'at_retail' => round((float) $products->sum(fn (Product $p) => (float) $p->price * (float) $p->stock), 2),
            'by_category' => $products
                ->groupBy('category')
                ->map(fn ($rows, $category) => [
                    'label' => (string) ($category ?: '—'),
                    'units' => round((float) $rows->sum('stock'), 2),
                    'at_cost' => round((float) $rows->sum(fn (Product $p) => (float) $p->cost * (float) $p->stock), 2),
                ])
                ->values()
                ->all(),
        ];
    }

    public function sales($from, $to): array
    {
        return $this->soldItems($from, $to)
            ->groupBy('itemable_id')
            ->map(function ($rows) {
                $product = $rows->first()->itemable;

                return [
                    'sku' => $product?->sku,
                    'name' => $product?->name ?? $rows->first()->description,
                    'category' => $product?->category,
                    'quantity' => round((float) $rows->sum('quantity'), 2),
                    'revenue' => round((float) $rows->sum('total'), 2),
                ];
            })
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    public function bestSellers($from, $to, int $limit = 20): array
    {
        return collect($this->sales($from, $to))->take($limit)->all();
    }

    /** In stock, but nothing sold in the window — money sitting on a shelf. */
    public function deadStock($from, $to): array
    {
        $sold = $this->soldItems($from, $to)->pluck('itemable_id')->unique();

        return Product::where('stock', '>', 0)
            ->whereNotIn('id', $sold)
            ->get()
            ->map(fn (Product $p) => [
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category,
                'stock' => (float) $p->stock,
                'tied_up' => round((float) $p->cost * (float) $p->stock, 2),
            ])
            ->sortByDesc('tied_up')
            ->values()
            ->all();
    }

    public function salesByDay(int $days): array
    {
        return $this->dailySeries(
            InvoiceItem::where('itemable_type', Product::class)
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->select('invoice_items.*'),
            'invoices.issued_at',
            $days,
            'invoice_items.total'
        );
    }

    public function grossMargin($from, $to): array
    {
        $items = $this->soldItems($from, $to);
        $revenue = (float) $items->sum('total');
        $cost = (float) $items->sum(fn (InvoiceItem $item) => (float) ($item->itemable?->cost ?? 0) * (float) $item->quantity);

        return [
            'revenue' => round($revenue, 2),
            'cost_of_goods' => round($cost, 2),
            'gross_margin' => round($revenue - $cost, 2),
            'margin_percent' => $revenue ? round(($revenue - $cost) / $revenue * 100, 1) : 0.0,
        ];
    }

    public function stockMovements($from, $to): array
    {
        return StockMovement::whereBetween('created_at', [$from, $to])
            ->with('product:id,sku,name', 'user:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StockMovement $m) => [
                'date' => $m->created_at->toDateTimeString(),
                'sku' => $m->product?->sku,
                'product' => $m->product?->name,
                'type' => $m->type,
                'quantity' => (float) $m->quantity,
                'stock_after' => (float) $m->stock_after,
                'reason' => $m->reason,
                'by' => $m->user?->name,
            ])
            ->all();
    }

    public function stockAdjustments($from, $to): array
    {
        return collect($this->stockMovements($from, $to))
            ->filter(fn (array $row) => $row['type'] === 'adjustment')
            ->values()
            ->all();
    }

    public function movementsByType($from, $to): array
    {
        return $this->breakdown(
            StockMovement::whereBetween('created_at', [$from, $to]),
            'type',
            'quantity'
        );
    }

    /** @return \Illuminate\Support\Collection<int, InvoiceItem> */
    protected function soldItems($from, $to)
    {
        return InvoiceItem::where('itemable_type', Product::class)
            ->whereHas('invoice', fn ($q) => $q->whereBetween('issued_at', [$from, $to]))
            ->with('itemable')
            ->get();
    }
}
