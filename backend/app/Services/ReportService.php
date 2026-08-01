<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Transaction;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every number the dashboards and the report screens show. Each method is a
 * named report so the API can expose them as a catalogue.
 */
class ReportService
{
    /** The manager app home screen. */
    public function dashboard(): array
    {
        $today = today();
        $monthStart = $today->copy()->startOfMonth();

        return [
            'members' => [
                'total' => Member::count(),
                'active' => Member::active()->count(),
                'new_today' => Member::whereDate('created_at', $today)->count(),
                'new_this_month' => Member::where('created_at', '>=', $monthStart)->count(),
            ],
            'attendance' => [
                'checkins_today' => Attendance::whereDate('checked_in_at', $today)->count(),
                'checkouts_today' => Attendance::whereDate('checked_out_at', $today)->count(),
                'inside_now' => Attendance::stillInside()->count(),
                'checkins_this_month' => Attendance::where('checked_in_at', '>=', $monthStart)->count(),
            ],
            'revenue' => [
                'today' => $this->revenueBetween($today->copy()->startOfDay(), $today->copy()->endOfDay()),
                'month' => $this->revenueBetween($monthStart, $today->copy()->endOfDay()),
                'expense_month' => $this->expenseBetween($monthStart, $today->copy()->endOfDay()),
            ],
            'memberships' => [
                'active' => Membership::active()->count(),
                'expiring_7_days' => Membership::expiringWithin(7)->count(),
                'renewals_today' => Membership::whereDate('created_at', $today)->count(),
                'remaining_sessions' => (int) Membership::active()->sum('remaining_sessions'),
            ],
            'classes' => [
                'sessions_today' => ClassSession::whereDate('starts_at', $today)->count(),
                'bookings_today' => ClassBooking::whereDate('booked_at', $today)->where('status', '!=', 'cancelled')->count(),
            ],
            'charts' => [
                'revenue_last_30_days' => $this->revenueSeries(30),
                'attendance_last_30_days' => $this->attendanceSeries(30),
                'attendance_by_hour' => $this->attendanceByHour(),
            ],
        ];
    }

    public function revenueBetween(CarbonImmutable|Carbon $from, CarbonImmutable|Carbon $to): float
    {
        return (float) Transaction::income()->between($from, $to)->sum('amount');
    }

    public function expenseBetween(CarbonImmutable|Carbon $from, CarbonImmutable|Carbon $to): float
    {
        return (float) Transaction::expense()->between($from, $to)->sum('amount');
    }

    /** @return Collection<int, array{date:string, total:float}> */
    public function revenueSeries(int $days = 30): Collection
    {
        $from = today()->subDays($days - 1);

        $rows = Transaction::income()
            ->where('occurred_at', '>=', $from)
            ->select(DB::raw('date(occurred_at) as day'), DB::raw('sum(amount) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->fillDays($days, fn (string $day) => (float) ($rows[$day] ?? 0));
    }

    /** @return Collection<int, array{date:string, total:float}> */
    public function attendanceSeries(int $days = 30): Collection
    {
        $from = today()->subDays($days - 1);

        $rows = Attendance::where('checked_in_at', '>=', $from)
            ->select(DB::raw('date(checked_in_at) as day'), DB::raw('count(*) as total'))
            ->groupBy('day')
            ->pluck('total', 'day');

        return $this->fillDays($days, fn (string $day) => (int) ($rows[$day] ?? 0));
    }

    /** Busiest hours of the club, used to plan staffing and pool sessions. */
    public function attendanceByHour(int $days = 30): array
    {
        $rows = Attendance::where('checked_in_at', '>=', today()->subDays($days))
            ->get(['checked_in_at'])
            ->groupBy(fn (Attendance $a) => $a->checked_in_at->format('H'))
            ->map->count();

        return collect(range(0, 23))
            ->map(fn (int $hour) => [
                'hour' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT),
                'total' => (int) ($rows[str_pad((string) $hour, 2, '0', STR_PAD_LEFT)] ?? 0),
            ])
            ->all();
    }

    public function revenueByCategory($from, $to): array
    {
        return Transaction::income()->between($from, $to)
            ->select('category', DB::raw('sum(amount) as total'))
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    public function revenueByMethod($from, $to): array
    {
        return Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', 'paid')
            ->select('method', DB::raw('sum(amount) as total'))
            ->groupBy('method')
            ->pluck('total', 'method')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    public function expenseByCategory($from, $to): array
    {
        return Transaction::expense()->between($from, $to)
            ->select('category', DB::raw('sum(amount) as total'))
            ->groupBy('category')
            ->pluck('total', 'category')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /** Income minus expense for an arbitrary window. */
    public function profitAndLoss($from, $to): array
    {
        $income = $this->revenueBetween($from, $to);
        $expense = $this->expenseBetween($from, $to);

        return [
            'income' => $income,
            'expense' => $expense,
            'profit' => round($income - $expense, 2),
            'income_by_category' => $this->revenueByCategory($from, $to),
            'expense_by_category' => $this->expenseByCategory($from, $to),
        ];
    }

    /** The daily till a cashier closes at the end of a shift. */
    public function dailyRegister(?string $date = null): array
    {
        $day = CarbonImmutable::parse($date ?? today());
        $from = $day->startOfDay();
        $to = $day->endOfDay();

        return [
            'date' => $day->toDateString(),
            'income' => $this->revenueBetween($from, $to),
            'expense' => $this->expenseBetween($from, $to),
            'net' => round($this->revenueBetween($from, $to) - $this->expenseBetween($from, $to), 2),
            'by_method' => $this->revenueByMethod($from, $to),
            'transactions' => Transaction::between($from, $to)->latest('occurred_at')->get(),
        ];
    }

    /** Members who stopped coming: no check-in in the given window. */
    public function inactiveMembers(int $days = 30)
    {
        return Member::active()
            ->whereDoesntHave('attendances', fn ($q) => $q->where('checked_in_at', '>=', now()->subDays($days)))
            ->withCount('attendances')
            ->orderBy('last_name')
            ->get();
    }

    public function expiringMemberships(int $days = 7)
    {
        return Membership::expiringWithin($days)
            ->with('member', 'plan')
            ->orderBy('ends_at')
            ->get();
    }

    public function topMembersByAttendance(int $days = 30, int $limit = 20)
    {
        return Member::withCount([
            'attendances' => fn ($q) => $q->where('checked_in_at', '>=', now()->subDays($days)),
        ])
            ->orderByDesc('attendances_count')
            ->limit($limit)
            ->get();
    }

    public function coachPerformance($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->with('coach')
            ->get()
            ->groupBy('coach_id')
            ->map(fn ($sessions) => [
                'coach' => $sessions->first()->coach?->full_name,
                'sessions' => $sessions->count(),
                'bookings' => (int) $sessions->sum('booked_count'),
                'revenue' => round($sessions->sum(fn ($s) => (float) $s->price * $s->booked_count), 2),
            ])
            ->values()
            ->all();
    }

    public function classOccupancy($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->with('gymClass')
            ->get()
            ->groupBy('gym_class_id')
            ->map(function ($sessions) {
                $capacity = (int) $sessions->sum('capacity');
                $booked = (int) $sessions->sum('booked_count');

                return [
                    'class' => $sessions->first()->gymClass?->translate('name'),
                    'sessions' => $sessions->count(),
                    'capacity' => $capacity,
                    'booked' => $booked,
                    'occupancy_percent' => $capacity > 0 ? round($booked / $capacity * 100, 1) : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    public function membershipMix(): array
    {
        return Membership::active()
            ->with('plan')
            ->get()
            ->groupBy('membership_plan_id')
            ->map(fn ($memberships) => [
                'plan' => $memberships->first()->plan?->translate('name'),
                'count' => $memberships->count(),
                'revenue' => round($memberships->sum('price'), 2),
            ])
            ->values()
            ->all();
    }

    public function genderSplit(): array
    {
        return Member::select('gender', DB::raw('count(*) as total'))
            ->groupBy('gender')
            ->pluck('total', 'gender')
            ->all();
    }

    public function lowStockProducts()
    {
        return Product::active()->lowStock()->orderBy('stock')->get();
    }

    public function productSales($from, $to): array
    {
        return DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.itemable_type', Product::class)
            ->whereBetween('invoices.issued_at', [$from, $to])
            ->when(app(TenantContext::class)->id(), fn ($q, $tenantId) => $q->where('invoices.tenant_id', $tenantId))
            ->select('invoice_items.description', DB::raw('sum(invoice_items.quantity) as quantity'), DB::raw('sum(invoice_items.total) as total'))
            ->groupBy('invoice_items.description')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'product' => $row->description,
                'quantity' => (float) $row->quantity,
                'total' => (float) $row->total,
            ])
            ->all();
    }

    /** The catalogue the reports screen lists. */
    public function catalogue(): array
    {
        return [
            ['key' => 'dashboard', 'group' => 'overview'],
            ['key' => 'daily_register', 'group' => 'finance'],
            ['key' => 'profit_and_loss', 'group' => 'finance'],
            ['key' => 'revenue_by_category', 'group' => 'finance'],
            ['key' => 'revenue_by_method', 'group' => 'finance'],
            ['key' => 'expense_by_category', 'group' => 'finance'],
            ['key' => 'revenue_series', 'group' => 'finance'],
            ['key' => 'product_sales', 'group' => 'shop'],
            ['key' => 'low_stock', 'group' => 'shop'],
            ['key' => 'inactive_members', 'group' => 'members'],
            ['key' => 'expiring_memberships', 'group' => 'members'],
            ['key' => 'top_members_by_attendance', 'group' => 'members'],
            ['key' => 'membership_mix', 'group' => 'members'],
            ['key' => 'gender_split', 'group' => 'members'],
            ['key' => 'attendance_series', 'group' => 'attendance'],
            ['key' => 'attendance_by_hour', 'group' => 'attendance'],
            ['key' => 'coach_performance', 'group' => 'coaches'],
            ['key' => 'class_occupancy', 'group' => 'classes'],
        ];
    }

    protected function fillDays(int $days, callable $resolver): Collection
    {
        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($resolver) {
                $day = today()->subDays($offset)->toDateString();

                return ['date' => $day, 'total' => $resolver($day)];
            })
            ->values();
    }
}
