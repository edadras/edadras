<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Member;
use App\Models\Product;
use App\Models\Transaction;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\MembershipService;
use App\Services\ReportService;
use App\Services\WalletService;
use RuntimeException;
use Tests\ClubTestCase;

/** Invoicing, payments, the cash box, the wallet and stock. */
class FinanceTest extends ClubTestCase
{
    protected InvoiceService $invoices;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoices = app(InvoiceService::class);

        $this->member = Member::create([
            'code' => '1001',
            'first_name' => 'Test',
            'last_name' => 'Member',
            'phone' => '09120000000',
        ]);
    }

    public function test_selling_a_membership_raises_an_invoice(): void
    {
        $plan = $this->plan('duration');

        app(MembershipService::class)->sell($this->member, $plan);

        $invoice = Invoice::first();

        $this->assertNotNull($invoice);
        $this->assertSame('unpaid', $invoice->status);
        $this->assertEquals((float) $plan->price, (float) $invoice->total);
    }

    public function test_paying_in_full_marks_the_invoice_paid(): void
    {
        app(MembershipService::class)->sell($this->member, $this->plan('duration'));
        $invoice = Invoice::first();

        $this->invoices->pay($invoice, (float) $invoice->total, 'cash');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0.0, $invoice->fresh()->balance());
    }

    public function test_a_part_payment_leaves_a_balance(): void
    {
        app(MembershipService::class)->sell($this->member, $this->plan('duration'));
        $invoice = Invoice::first();
        $half = round((float) $invoice->total / 2, 2);

        $this->invoices->pay($invoice, $half, 'card');

        $this->assertSame('partial', $invoice->fresh()->status);
        $this->assertEqualsWithDelta($half, $invoice->fresh()->balance(), 0.01);
    }

    public function test_every_payment_lands_in_the_cash_box(): void
    {
        app(MembershipService::class)->sell($this->member, $this->plan('duration'));
        $invoice = Invoice::first();

        $this->invoices->pay($invoice, 500_000, 'cash');

        $this->assertDatabaseHas('transactions', [
            'type' => 'income',
            'category' => 'membership',
            'amount' => 500000,
        ]);
    }

    public function test_invoice_numbers_are_sequential_inside_the_club(): void
    {
        $service = app(MembershipService::class);

        $service->sell($this->member, $this->plan('duration'));
        $service->sell($this->member, $this->plan('session'));

        $numbers = Invoice::orderBy('id')->pluck('number')->all();

        $this->assertSame(['INV-000001', 'INV-000002'], $numbers);
    }

    public function test_selling_a_product_bills_it_and_takes_it_off_the_shelf(): void
    {
        $product = Product::create(['name' => 'Protein', 'price' => 100_000, 'cost' => 70_000, 'stock' => 10]);

        $invoice = $this->invoices->forProducts([
            ['product_id' => $product->id, 'quantity' => 3],
        ], $this->member->id);

        $this->assertEquals(300_000, (float) $invoice->total);
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'quantity' => -3, 'stock_after' => 7]);
    }

    public function test_a_sale_beyond_the_shelf_is_refused(): void
    {
        $product = Product::create(['name' => 'Shirt', 'price' => 100, 'stock' => 1]);

        $this->expectException(RuntimeException::class);

        $this->invoices->forProducts([['product_id' => $product->id, 'quantity' => 5]]);
    }

    public function test_a_refused_sale_leaves_the_stock_untouched(): void
    {
        $ok = Product::create(['name' => 'Water', 'price' => 20, 'stock' => 50]);
        $short = Product::create(['name' => 'Shirt', 'price' => 100, 'stock' => 1]);

        try {
            $this->invoices->forProducts([
                ['product_id' => $ok->id, 'quantity' => 2],
                ['product_id' => $short->id, 'quantity' => 5],
            ]);
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(50, $ok->fresh()->stock);
        $this->assertSame(0, Invoice::count());
    }

    public function test_receiving_stock_records_a_movement(): void
    {
        $product = Product::create(['name' => 'Creatine', 'price' => 100, 'stock' => 2]);

        app(InventoryService::class)->receive($product, 8, 'purchase');

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'in', 'quantity' => 8, 'stock_after' => 10]);
    }

    public function test_a_stock_take_writes_the_difference(): void
    {
        $product = Product::create(['name' => 'Towel', 'price' => 100, 'stock' => 20]);

        app(InventoryService::class)->adjust($product, 17, 'stock_take');

        $this->assertSame(17, $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'adjust', 'quantity' => -3]);
    }

    public function test_low_stock_is_reported(): void
    {
        Product::create(['name' => 'Low', 'price' => 1, 'stock' => 2, 'min_stock' => 5]);
        Product::create(['name' => 'Fine', 'price' => 1, 'stock' => 50, 'min_stock' => 5]);

        $low = Product::active()->lowStock()->pluck('name')->all();

        $this->assertSame(['Low'], $low);
    }

    public function test_the_wallet_tracks_credits_and_debits(): void
    {
        $wallets = app(WalletService::class);

        $wallets->credit($this->member, 500_000, 'top up');
        $wallets->debit($this->member, 200_000, 'class fee');

        $this->assertEquals(300_000, (float) $wallets->wallet($this->member)->balance);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    public function test_the_wallet_cannot_go_negative(): void
    {
        $wallets = app(WalletService::class);
        $wallets->credit($this->member, 100, 'top up');

        $this->expectException(RuntimeException::class);

        $wallets->debit($this->member, 500, 'too much');
    }

    public function test_the_daily_register_balances_income_against_expense(): void
    {
        app(MembershipService::class)->sell($this->member, $this->plan('duration'));
        $invoice = Invoice::first();
        $this->invoices->pay($invoice, 1_000_000, 'cash');

        $this->invoices->recordExpense(['amount' => 400_000, 'category' => 'bills', 'description' => 'electricity']);

        $register = app(ReportService::class)->dailyRegister();

        $this->assertEquals(1_000_000, $register['income']);
        $this->assertEquals(400_000, $register['expense']);
        $this->assertEquals(600_000, $register['net']);
    }

    public function test_the_expense_endpoint_writes_to_the_cash_box(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/finance/expenses', [
                'amount' => 750_000,
                'category' => 'rent',
                'description' => 'Monthly rent',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('transactions', ['type' => 'expense', 'category' => 'rent', 'amount' => 750000]);
    }

    public function test_income_and_expense_are_scoped_to_the_period(): void
    {
        Transaction::create([
            'type' => Transaction::INCOME,
            'category' => 'membership',
            'amount' => 900,
            'occurred_at' => now()->subMonths(2),
        ]);

        $reports = app(ReportService::class);

        $this->assertEquals(0.0, $reports->revenueBetween(today()->startOfMonth(), now()));
        $this->assertEquals(900.0, $reports->revenueBetween(now()->subMonths(3), now()));
    }
}
