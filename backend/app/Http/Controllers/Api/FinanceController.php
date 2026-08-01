<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\InvoiceService;
use App\Services\ReportService;
use App\Services\WalletService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Cash box, invoices, payments and the member wallet. */
class FinanceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly WalletService $wallets,
        private readonly ReportService $reports,
    ) {}

    public function transactions(Request $request): JsonResponse
    {
        $this->authorize('finance.view');

        $from = $request->date('from') ?? today()->startOfMonth();
        $to = $request->date('to') ?? today()->endOfDay();

        return response()->json(
            Transaction::query()
                ->between($from, $to)
                ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
                ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
                ->with('member:id,first_name,last_name', 'user:id,name')
                ->latest('occurred_at')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $this->authorize('finance.create');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'category' => ['required', Rule::in(['rent', 'salary', 'bills', 'equipment', 'marketing', 'tax', 'supplies', 'other'])],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'online'])],
            'description' => ['nullable', 'string', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->invoices->recordExpense($data + ['user_id' => $request->user()->id]),
            201
        );
    }

    public function storeIncome(Request $request): JsonResponse
    {
        $this->authorize('finance.create');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'category' => ['nullable', 'string', 'max:60'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'online'])],
            'description' => ['nullable', 'string', 'max:500'],
            'member_id' => ['nullable', 'exists:members,id'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return response()->json(Transaction::create($data + [
            'type' => Transaction::INCOME,
            'category' => $data['category'] ?? 'other',
            'user_id' => $request->user()->id,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ]), 201);
    }

    /** The till a cashier reconciles at the end of a shift. */
    public function dailyRegister(Request $request): JsonResponse
    {
        $this->authorize('finance.view');

        return response()->json($this->reports->dailyRegister($request->query('date')));
    }

    public function invoices(Request $request): JsonResponse
    {
        $this->authorize('invoices.view');

        return response()->json(
            Invoice::query()
                ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->with('member:id,code,first_name,last_name', 'items')
                ->latest('issued_at')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function showInvoice(Invoice $invoice): JsonResponse
    {
        $this->authorize('invoices.view');

        return response()->json($invoice->load('items', 'payments', 'member'));
    }

    /** A free form invoice, for anything not sold through a plan or product. */
    public function storeInvoice(Request $request): JsonResponse
    {
        $this->authorize('invoices.create');

        $data = $request->validate([
            'member_id' => ['nullable', 'exists:members,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $invoice = DB::transaction(function () use ($data, $request) {
            $invoice = Invoice::create([
                'member_id' => $data['member_id'] ?? null,
                'number' => 'INV-'.str_pad((string) (Invoice::withoutGlobalScopes()->where('tenant_id', $request->user()->tenant_id)->count() + 1), 6, '0', STR_PAD_LEFT),
                'issued_at' => now(),
                'due_at' => $data['due_at'] ?? null,
                'tax' => $data['tax'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $item) {
                $invoice->items()->create($item);
            }

            return tap($invoice->refresh()->recalculate())->save();
        });

        return response()->json($invoice->load('items'), 201);
    }

    public function payInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('payments.create');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'gateway' => ['nullable', 'string', 'max:60'],
        ]);

        // Paying from the wallet has to move the credit as well.
        if ($data['method'] === 'wallet') {
            abort_if($invoice->member_id === null, 422, __('general.wallet_needs_member'));

            $this->wallets->debit(
                $invoice->member,
                $data['amount'],
                __('general.invoice_payment', ['number' => $invoice->number]),
                $invoice,
            );
        }

        $payment = $this->invoices->pay($invoice, $data['amount'], $data['method'], $data + [
            'received_by' => $request->user()->id,
        ]);

        return response()->json([
            'payment' => $payment,
            'invoice' => $invoice->fresh('items', 'payments'),
        ], 201);
    }

    /** Printable invoice. */
    public function invoicePdf(Invoice $invoice): mixed
    {
        $this->authorize('invoices.view');

        $invoice->load('items', 'payments', 'member', 'tenant');

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'club' => $invoice->tenant,
            'direction' => config('gymflow.locales.'.app()->getLocale().'.dir', 'ltr'),
        ]);

        return $pdf->download("{$invoice->number}.pdf");
    }

    public function wallet(Member $member): JsonResponse
    {
        $this->authorize('wallet.view');

        $wallet = $this->wallets->wallet($member);

        return response()->json([
            'wallet' => $wallet,
            'transactions' => $wallet->transactions()->limit(50)->get(),
        ]);
    }

    public function topUpWallet(Request $request, Member $member): JsonResponse
    {
        $this->authorize('wallet.update');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer', 'online'])],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = $this->wallets->credit($member, $data['amount'], $data['description'] ?? __('general.wallet_topup'));

        // Money entering the wallet is money entering the club.
        Transaction::create([
            'type' => Transaction::INCOME,
            'category' => 'wallet',
            'amount' => $data['amount'],
            'method' => $data['method'] ?? 'cash',
            'description' => __('general.wallet_topup'),
            'member_id' => $member->id,
            'user_id' => $request->user()->id,
            'occurred_at' => now(),
        ]);

        return response()->json($transaction, 201);
    }
}
