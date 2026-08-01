<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Transaction;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Invoicing and the cash box. Every paid amount lands in transactions so the
 * daily till and the accounting reports agree with each other.
 */
class InvoiceService
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly InventoryService $inventory,
    ) {}

    public function forMembership(Membership $membership): Invoice
    {
        return DB::transaction(function () use ($membership) {
            $invoice = $this->open($membership->member_id);

            $invoice->items()->create([
                'itemable_type' => Membership::class,
                'itemable_id' => $membership->id,
                'description' => $membership->plan?->translate('name') ?? 'Membership',
                'quantity' => 1,
                'unit_price' => $membership->price,
                'discount' => $membership->discount,
            ]);

            return tap($invoice->refresh()->recalculate())->save();
        });
    }

    /**
     * Point of sale for the shop: decrements stock and bills in one go.
     *
     * @param  array<int, array{product_id:int, quantity:int, discount?:float}>  $lines
     */
    public function forProducts(array $lines, ?int $memberId = null): Invoice
    {
        return DB::transaction(function () use ($lines, $memberId) {
            $invoice = $this->open($memberId);

            foreach ($lines as $line) {
                $product = Product::findOrFail($line['product_id']);
                $quantity = (int) $line['quantity'];

                $this->inventory->release($product, $quantity, 'sale', $invoice);

                $invoice->items()->create([
                    'itemable_type' => Product::class,
                    'itemable_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'discount' => $line['discount'] ?? 0,
                ]);
            }

            return tap($invoice->refresh()->recalculate())->save();
        });
    }

    public function pay(Invoice $invoice, float $amount, string $method = 'cash', array $attributes = []): Payment
    {
        return DB::transaction(function () use ($invoice, $amount, $method, $attributes) {
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'member_id' => $invoice->member_id,
                'amount' => $amount,
                'method' => $method,
                'gateway' => $attributes['gateway'] ?? null,
                'reference' => $attributes['reference'] ?? null,
                'status' => 'paid',
                'paid_at' => now(),
                'received_by' => $attributes['received_by'] ?? auth()->id(),
            ]);

            $invoice->refresh()->recalculate()->save();

            $this->recordIncome($payment, $invoice);
            $this->settleMembershipPayments($invoice);

            return $payment;
        });
    }

    /** Free form cash box entry that is not tied to an invoice. */
    public function recordExpense(array $attributes): Transaction
    {
        return Transaction::create([
            'type' => Transaction::EXPENSE,
            'category' => $attributes['category'] ?? 'other',
            'amount' => $attributes['amount'],
            'method' => $attributes['method'] ?? 'cash',
            'description' => $attributes['description'] ?? null,
            'user_id' => $attributes['user_id'] ?? auth()->id(),
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }

    protected function open(?int $memberId): Invoice
    {
        return Invoice::create([
            'member_id' => $memberId,
            'number' => $this->nextNumber(),
            'issued_at' => now(),
            'status' => 'unpaid',
            'created_by' => auth()->id(),
        ]);
    }

    /** INV-000123, unique inside the club. */
    protected function nextNumber(): string
    {
        $tenantId = $this->tenancy->id();

        $last = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

        return 'INV-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    protected function recordIncome(Payment $payment, Invoice $invoice): void
    {
        $category = $invoice->items->first()?->itemable_type === Product::class ? 'shop' : 'membership';

        Transaction::create([
            'type' => Transaction::INCOME,
            'category' => $category,
            'amount' => $payment->amount,
            'method' => $payment->method,
            'description' => 'Invoice '.$invoice->number,
            'reference_type' => Payment::class,
            'reference_id' => $payment->id,
            'member_id' => $invoice->member_id,
            'user_id' => $payment->received_by,
            'occurred_at' => $payment->paid_at ?? now(),
        ]);
    }

    /** Keeps membership.paid_amount in step with the invoice it belongs to. */
    protected function settleMembershipPayments(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        foreach ($invoice->items->where('itemable_type', Membership::class) as $item) {
            $membership = $item->itemable;

            $membership?->update([
                'paid_amount' => min((float) $item->total, (float) $invoice->paid_amount),
            ]);
        }
    }
}
