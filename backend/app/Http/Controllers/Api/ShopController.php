<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** The shop counter and the stock room behind it. */
class ShopController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('shop.view');

        return response()->json(
            Product::query()
                ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
                ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
                ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
                ->orderBy('name')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('shop.create');

        return response()->json(Product::create($this->validated($request)), 201);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorize('shop.update');

        // Stock only ever moves through the inventory service, so the
        // movement trail can never disagree with the on hand number.
        $product->update(collect($this->validated($request, $product))->except('stock')->all());

        return response()->json($product->fresh());
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('shop.delete');

        $product->delete();

        return response()->json(['message' => __('general.deleted')]);
    }

    /** Point of sale: bills the basket and takes the stock down. */
    public function sell(Request $request): JsonResponse
    {
        $this->authorize('shop.create');

        $data = $request->validate([
            'member_id' => ['nullable', 'exists:members,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'pay_now' => ['nullable', 'boolean'],
            'method' => ['nullable', Rule::in(Payment::METHODS)],
        ]);

        try {
            $invoice = $this->invoices->forProducts($data['items'], $data['member_id'] ?? null);
        } catch (RuntimeException $e) {
            return response()->json(['message' => __('general.out_of_stock')], 422);
        }

        if ($data['pay_now'] ?? false) {
            $this->invoices->pay($invoice, (float) $invoice->total, $data['method'] ?? 'cash', [
                'received_by' => $request->user()->id,
            ]);
        }

        return response()->json($invoice->fresh('items', 'payments'), 201);
    }

    public function receiveStock(Request $request, Product $product): JsonResponse
    {
        $this->authorize('inventory.update');

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'record_expense' => ['nullable', 'boolean'],
        ]);

        $movement = $this->inventory->receive($product, $data['quantity'], $data['reason'] ?? 'purchase');

        if (($data['record_expense'] ?? false) && ! empty($data['cost'])) {
            $this->invoices->recordExpense([
                'amount' => $data['cost'] * $data['quantity'],
                'category' => 'supplies',
                'description' => __('general.stock_purchase', ['product' => $product->name]),
                'user_id' => $request->user()->id,
            ]);
        }

        return response()->json(['movement' => $movement, 'product' => $product->fresh()], 201);
    }

    public function adjustStock(Request $request, Product $product): JsonResponse
    {
        $this->authorize('inventory.update');

        $data = $request->validate([
            'counted_stock' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $movement = $this->inventory->adjust($product, $data['counted_stock'], $data['reason'] ?? 'stock_take');

        return response()->json(['movement' => $movement, 'product' => $product->fresh()]);
    }

    public function movements(Request $request, Product $product): JsonResponse
    {
        $this->authorize('inventory.view');

        return response()->json(
            $product->movements()->with('user:id,name')->latest()->paginate($request->integer('per_page', 50))
        );
    }

    protected function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => [$product ? 'sometimes' : 'required', 'string', 'max:150'],
            'sku' => ['nullable', 'string', 'max:60'],
            'category' => ['nullable', Rule::in(['supplement', 'clothing', 'drink', 'equipment', 'other'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => [$product ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
