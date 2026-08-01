<?php

namespace App\Reports;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Reports\Concerns\AggregatesRows;
use Illuminate\Support\Facades\DB;

/** What the club is selling, what it is about to lose and what it still owes. */
class MembershipReports
{
    use AggregatesRows;

    public function active(): array
    {
        return Membership::active()
            ->with('member:id,code,first_name,last_name', 'plan:id,name')
            ->orderBy('ends_at')
            ->get()
            ->map(fn (Membership $m) => $this->row($m))
            ->all();
    }

    public function expiring(int $days): array
    {
        return Membership::expiringWithin($days)
            ->with('member:id,code,first_name,last_name,phone', 'plan:id,name')
            ->orderBy('ends_at')
            ->get()
            ->map(fn (Membership $m) => $this->row($m) + ['phone' => $m->member?->phone])
            ->all();
    }

    public function expired($from, $to): array
    {
        return Membership::where('status', 'expired')
            ->whereBetween('ends_at', [$from, $to])
            ->with('member:id,code,first_name,last_name,phone', 'plan:id,name')
            ->orderByDesc('ends_at')
            ->get()
            ->map(fn (Membership $m) => $this->row($m) + ['phone' => $m->member?->phone])
            ->all();
    }

    public function frozen(): array
    {
        return Membership::where('status', 'frozen')
            ->with('member:id,code,first_name,last_name', 'plan:id,name')
            ->get()
            ->map(fn (Membership $m) => $this->row($m) + [
                'frozen_from' => $m->frozen_from?->toDateString(),
                'frozen_until' => $m->frozen_until?->toDateString(),
            ])
            ->all();
    }

    public function cancelled($from, $to): array
    {
        return Membership::where('status', 'cancelled')
            ->whereBetween('updated_at', [$from, $to])
            ->with('member:id,code,first_name,last_name', 'plan:id,name')
            ->get()
            ->map(fn (Membership $m) => $this->row($m))
            ->all();
    }

    public function byStatus(): array
    {
        return $this->breakdown(Membership::query(), 'status');
    }

    public function byType(): array
    {
        return $this->breakdown(Membership::query(), 'type');
    }

    /** How the active book splits across the plans on sale. */
    public function mix(): array
    {
        return Membership::active()
            ->with('plan:id,name')
            ->get()
            ->groupBy('membership_plan_id')
            ->map(fn ($rows) => [
                'label' => $rows->first()->plan?->name ?? '—',
                'total' => $rows->count(),
                'revenue' => round((float) $rows->sum('paid_amount'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public function planPopularity($from, $to): array
    {
        return Membership::whereBetween('created_at', [$from, $to])
            ->with('plan:id,name,price,type')
            ->get()
            ->groupBy('membership_plan_id')
            ->map(fn ($rows) => [
                'plan' => $rows->first()->plan?->name ?? '—',
                'type' => $rows->first()->type,
                'sold' => $rows->count(),
                'revenue' => round((float) $rows->sum('paid_amount'), 2),
                'discount' => round((float) $rows->sum('discount'), 2),
            ])
            ->sortByDesc('sold')
            ->values()
            ->all();
    }

    public function planRevenue($from, $to): array
    {
        return $this->planPopularity($from, $to);
    }

    public function soldByDay(int $days): array
    {
        return $this->dailySeries(Membership::query(), 'created_at', $days);
    }

    public function soldByMonth(int $months = 12): array
    {
        return $this->monthlySeries(Membership::query(), 'created_at', $months);
    }

    public function revenueByMonth(int $months = 12): array
    {
        return $this->monthlySeries(Membership::query(), 'created_at', $months, 'paid_amount');
    }

    /**
     * A member who bought again within thirty days of their last pass ending
     * counts as renewed. Everyone else lapsed.
     */
    public function renewalRate(int $months = 6): array
    {
        $ended = Membership::whereIn('status', ['expired', 'completed'])
            ->where('ends_at', '>=', today()->startOfMonth()->subMonths($months - 1))
            ->with('member.memberships')
            ->get();

        return $ended
            ->groupBy(fn (Membership $m) => $m->ends_at?->format('Y-m') ?? '—')
            ->map(function ($group, string $month) {
                $renewed = $group->filter(function (Membership $m) {
                    return $m->member?->memberships
                        ->contains(fn (Membership $other) => $other->id !== $m->id
                            && $other->created_at->between($m->ends_at, $m->ends_at?->copy()->addDays(30))) ?? false;
                })->count();

                return [
                    'month' => $month,
                    'ended' => $group->count(),
                    'renewed' => $renewed,
                    'rate' => $group->count() ? round($renewed / $group->count() * 100, 1) : 0.0,
                ];
            })
            ->sortBy('month')
            ->values()
            ->all();
    }

    public function averageDuration(): array
    {
        return MembershipPlan::withCount('memberships')
            ->get()
            ->map(fn (MembershipPlan $plan) => [
                'plan' => $plan->name,
                'type' => $plan->type,
                'duration_days' => $plan->duration_days,
                'session_count' => $plan->session_count,
                'price' => (float) $plan->price,
                'sold' => $plan->memberships_count,
            ])
            ->all();
    }

    public function sessionsRemaining(): array
    {
        $rows = Membership::active()->whereNotNull('remaining_sessions')->get();

        return [
            'passes' => $rows->count(),
            'sessions_outstanding' => (int) $rows->sum('remaining_sessions'),
            'sessions_sold' => (int) $rows->sum('total_sessions'),
            'average_remaining' => $rows->count() ? round($rows->avg('remaining_sessions'), 1) : 0.0,
            'exhausted_but_valid' => $rows->where('remaining_sessions', 0)->count(),
        ];
    }

    /**
     * Sessions already paid for but not yet used. It is money the club has
     * taken and still owes as service, so the accountant needs the number.
     */
    public function unusedSessionLiability(): array
    {
        return Membership::active()
            ->whereNotNull('remaining_sessions')
            ->where('remaining_sessions', '>', 0)
            ->with('plan:id,name')
            ->get()
            ->groupBy('membership_plan_id')
            ->map(function ($rows) {
                $liability = $rows->sum(function (Membership $m) {
                    $total = max(1, (int) $m->total_sessions);

                    return (float) $m->paid_amount / $total * $m->remaining_sessions;
                });

                return [
                    'plan' => $rows->first()->plan?->name ?? '—',
                    'passes' => $rows->count(),
                    'sessions_left' => (int) $rows->sum('remaining_sessions'),
                    'liability' => round($liability, 2),
                ];
            })
            ->sortByDesc('liability')
            ->values()
            ->all();
    }

    /** Passes sold but not fully paid for. */
    public function outstandingBalances(): array
    {
        return Membership::whereColumn('paid_amount', '<', DB::raw('price - discount'))
            ->with('member:id,code,first_name,last_name,phone', 'plan:id,name')
            ->get()
            ->map(fn (Membership $m) => $this->row($m) + [
                'phone' => $m->member?->phone,
                'due' => round((float) $m->price - (float) $m->discount - (float) $m->paid_amount, 2),
            ])
            ->all();
    }

    protected function row(Membership $m): array
    {
        return [
            'code' => $m->member?->code,
            'member' => $m->member?->full_name,
            'plan' => $m->plan?->name,
            'type' => $m->type,
            'status' => $m->status,
            'starts_at' => $m->starts_at?->toDateString(),
            'ends_at' => $m->ends_at?->toDateString(),
            'remaining_sessions' => $m->remaining_sessions,
            'paid_amount' => (float) $m->paid_amount,
        ];
    }
}
