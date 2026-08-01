<?php

namespace App\Reports;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Reports\Concerns\AggregatesRows;
use Illuminate\Support\Facades\DB;

/** The cash book, the invoices behind it and what is still owed. */
class FinanceReports
{
    use AggregatesRows;

    public function income($from, $to): float
    {
        return round((float) Transaction::income()->between($from, $to)->sum('amount'), 2);
    }

    public function expense($from, $to): float
    {
        return round((float) Transaction::expense()->between($from, $to)->sum('amount'), 2);
    }

    public function profitAndLoss($from, $to): array
    {
        $income = $this->income($from, $to);
        $expense = $this->expense($from, $to);

        return [
            'income' => $income,
            'expense' => $expense,
            'profit' => round($income - $expense, 2),
            'margin_percent' => $income ? round(($income - $expense) / $income * 100, 1) : 0.0,
            'income_by_category' => $this->incomeByCategory($from, $to),
            'expense_by_category' => $this->expenseByCategory($from, $to),
        ];
    }

    public function incomeByCategory($from, $to): array
    {
        return $this->breakdown(Transaction::income()->between($from, $to), 'category', 'amount');
    }

    public function expenseByCategory($from, $to): array
    {
        return $this->breakdown(Transaction::expense()->between($from, $to), 'category', 'amount');
    }

    public function incomeByMethod($from, $to): array
    {
        return $this->breakdown(Transaction::income()->between($from, $to), 'method', 'amount');
    }

    public function expenseByMethod($from, $to): array
    {
        return $this->breakdown(Transaction::expense()->between($from, $to), 'method', 'amount');
    }

    public function incomeSeries(int $days): array
    {
        return $this->dailySeries(Transaction::income(), 'occurred_at', $days, 'amount');
    }

    public function expenseSeries(int $days): array
    {
        return $this->dailySeries(Transaction::expense(), 'occurred_at', $days, 'amount');
    }

    /** Income and expense on the same axis, with the running balance. */
    public function cashFlow(int $days): array
    {
        $income = collect($this->incomeSeries($days))->keyBy('date');
        $expense = collect($this->expenseSeries($days))->keyBy('date');
        $running = 0.0;

        return $income
            ->map(function (array $row, string $date) use ($expense, &$running) {
                $out = (float) ($expense[$date]['total'] ?? 0);
                $net = round($row['total'] - $out, 2);
                $running = round($running + $net, 2);

                return ['date' => $date, 'income' => $row['total'], 'expense' => $out, 'net' => $net, 'running' => $running];
            })
            ->values()
            ->all();
    }

    public function incomeByMonth(int $months = 12): array
    {
        return $this->monthlySeries(Transaction::income(), 'occurred_at', $months, 'amount');
    }

    public function expenseByMonth(int $months = 12): array
    {
        return $this->monthlySeries(Transaction::expense(), 'occurred_at', $months, 'amount');
    }

    public function transactions($from, $to): array
    {
        return Transaction::between($from, $to)
            ->with('member:id,code,first_name,last_name', 'user:id,name')
            ->orderByDesc('occurred_at')
            ->get()
            ->map(fn (Transaction $t) => [
                'occurred_at' => $t->occurred_at?->toDateTimeString(),
                'type' => $t->type,
                'category' => $t->category,
                'method' => $t->method,
                'amount' => (float) $t->amount,
                'description' => $t->description,
                'member' => $t->member?->full_name,
                'recorded_by' => $t->user?->name,
            ])
            ->all();
    }

    /** The daily till a cashier closes at the end of a shift. */
    public function dailyRegister($date = null): array
    {
        $day = $date ? \Carbon\CarbonImmutable::parse($date) : today()->toImmutable();
        $from = $day->startOfDay();
        $to = $day->endOfDay();

        $income = $this->income($from, $to);
        $expense = $this->expense($from, $to);

        return [
            'date' => $day->toDateString(),
            'income' => $income,
            'expense' => $expense,
            'net' => round($income - $expense, 2),
            'by_method' => $this->incomeByMethod($from, $to),
            'by_category' => $this->incomeByCategory($from, $to),
            'transactions' => $this->transactions($from, $to),
        ];
    }

    public function invoices($from, $to): array
    {
        return Invoice::whereBetween('issued_at', [$from, $to])
            ->with('member:id,code,first_name,last_name')
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (Invoice $i) => $this->invoiceRow($i))
            ->all();
    }

    public function invoicesByStatus($from, $to): array
    {
        return $this->breakdown(Invoice::whereBetween('issued_at', [$from, $to]), 'status');
    }

    /** Issued, part paid or unpaid, and past its due date. */
    public function outstandingInvoices(): array
    {
        return Invoice::whereIn('status', ['issued', 'partial', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total')
            ->with('member:id,code,first_name,last_name,phone')
            ->orderBy('due_at')
            ->get()
            ->map(fn (Invoice $i) => $this->invoiceRow($i) + [
                'phone' => $i->member?->phone,
                'days_overdue' => $i->due_at && $i->due_at->isPast() ? (int) $i->due_at->diffInDays(now()) : 0,
            ])
            ->all();
    }

    public function paidInvoices($from, $to): array
    {
        return Invoice::where('status', 'paid')
            ->whereBetween('issued_at', [$from, $to])
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->map(fn (Invoice $i) => $this->invoiceRow($i))
            ->all();
    }

    public function payments($from, $to): array
    {
        return Payment::whereBetween('paid_at', [$from, $to])
            ->with('member:id,code,first_name,last_name')
            ->orderByDesc('paid_at')
            ->get()
            ->map(fn (Payment $p) => [
                'paid_at' => $p->paid_at?->toDateTimeString(),
                'member' => $p->member?->full_name,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'gateway' => $p->gateway,
                'reference' => $p->reference,
                'status' => $p->status,
            ])
            ->all();
    }

    public function paymentsByMethod($from, $to): array
    {
        return $this->breakdown(
            Payment::whereBetween('paid_at', [$from, $to])->where('status', 'paid'),
            'method',
            'amount'
        );
    }

    public function paymentsByGateway($from, $to): array
    {
        return $this->breakdown(
            Payment::whereBetween('paid_at', [$from, $to])->whereNotNull('gateway'),
            'gateway',
            'amount'
        );
    }

    public function paymentsByDay(int $days): array
    {
        return $this->dailySeries(Payment::where('status', 'paid'), 'paid_at', $days, 'amount');
    }

    public function refunds($from, $to): array
    {
        return Payment::whereIn('status', ['refunded', 'failed'])
            ->whereBetween('paid_at', [$from, $to])
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->map(fn (Payment $p) => [
                'paid_at' => $p->paid_at?->toDateTimeString(),
                'member' => $p->member?->full_name,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'status' => $p->status,
                'reference' => $p->reference,
            ])
            ->all();
    }

    public function averageTransactionValue($from, $to): array
    {
        $payments = Payment::whereBetween('paid_at', [$from, $to])->where('status', 'paid')->pluck('amount');
        $memberCount = Member::active()->count();
        $income = $this->income($from, $to);

        return [
            'payments' => $payments->count(),
            'total' => round((float) $payments->sum(), 2),
            'average' => $payments->count() ? round((float) $payments->avg(), 2) : 0.0,
            'largest' => round((float) ($payments->max() ?? 0), 2),
            'smallest' => round((float) ($payments->min() ?? 0), 2),
            'revenue_per_active_member' => $memberCount ? round($income / $memberCount, 2) : 0.0,
        ];
    }

    public function revenuePerMember($from, $to, int $limit = 50): array
    {
        return Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', 'paid')
            ->whereNotNull('member_id')
            ->select('member_id', DB::raw('sum(amount) as total'))
            ->groupBy('member_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->member?->code,
                'member' => $row->member?->full_name,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /** Every discount the desk gave away, so somebody can ask why. */
    public function discounts($from, $to): array
    {
        $onMemberships = Membership::whereBetween('created_at', [$from, $to])
            ->where('discount', '>', 0)
            ->with('member:id,code,first_name,last_name', 'plan:id,name')
            ->get()
            ->map(fn (Membership $m) => [
                'source' => 'membership',
                'date' => $m->created_at->toDateString(),
                'member' => $m->member?->full_name,
                'item' => $m->plan?->name,
                'discount' => (float) $m->discount,
            ]);

        $onInvoices = Invoice::whereBetween('issued_at', [$from, $to])
            ->where('discount', '>', 0)
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->map(fn (Invoice $i) => [
                'source' => 'invoice',
                'date' => $i->issued_at?->toDateString(),
                'member' => $i->member?->full_name,
                'item' => $i->number,
                'discount' => (float) $i->discount,
            ]);

        return $onMemberships->concat($onInvoices)->sortByDesc('discount')->values()->all();
    }

    public function walletBalances(): array
    {
        return Wallet::where('balance', '!=', 0)
            ->with('member:id,code,first_name,last_name')
            ->orderByDesc('balance')
            ->get()
            ->map(fn (Wallet $w) => [
                'code' => $w->member?->code,
                'member' => $w->member?->full_name,
                'balance' => (float) $w->balance,
            ])
            ->all();
    }

    public function walletMovements($from, $to): array
    {
        return WalletTransaction::whereBetween('created_at', [$from, $to])
            ->with('wallet.member:id,code,first_name,last_name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (WalletTransaction $t) => [
                'date' => $t->created_at->toDateTimeString(),
                'member' => $t->wallet?->member?->full_name,
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'balance_after' => (float) $t->balance_after,
                'description' => $t->description,
            ])
            ->all();
    }

    public function walletLiability(): array
    {
        $wallets = Wallet::where('balance', '>', 0)->get();

        return [
            'wallets_in_credit' => $wallets->count(),
            'total_liability' => round((float) $wallets->sum('balance'), 2),
            'largest_balance' => round((float) ($wallets->max('balance') ?? 0), 2),
            'average_balance' => $wallets->count() ? round((float) $wallets->avg('balance'), 2) : 0.0,
        ];
    }

    protected function invoiceRow(Invoice $i): array
    {
        return [
            'number' => $i->number,
            'code' => $i->member?->code,
            'member' => $i->member?->full_name,
            'issued_at' => $i->issued_at?->toDateString(),
            'due_at' => $i->due_at?->toDateString(),
            'subtotal' => (float) $i->subtotal,
            'discount' => (float) $i->discount,
            'total' => (float) $i->total,
            'paid_amount' => (float) $i->paid_amount,
            'due' => round((float) $i->total - (float) $i->paid_amount, 2),
            'status' => $i->status,
        ];
    }
}
