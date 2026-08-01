<?php

namespace App\Reports;

use App\Models\Member;
use App\Models\Payment;
use App\Reports\Concerns\AggregatesRows;
use Illuminate\Support\Facades\DB;

/** Who the club's members are, where they came from and who is drifting away. */
class MemberReports
{
    use AggregatesRows;

    public function directory(): array
    {
        return Member::with('activeMembership.plan')
            ->orderBy('last_name')
            ->get()
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'phone' => $m->phone,
                'gender' => $m->gender,
                'status' => $m->status,
                'joined_at' => $m->joined_at?->toDateString(),
                'plan' => $m->activeMembership?->plan?->name,
                'expires_at' => $m->activeMembership?->ends_at?->toDateString(),
            ])
            ->all();
    }

    public function newByDay(int $days): array
    {
        return $this->dailySeries(Member::query(), 'created_at', $days);
    }

    public function newByMonth(int $months = 12): array
    {
        return $this->monthlySeries(Member::query(), 'created_at', $months);
    }

    public function growth(int $months = 12): array
    {
        $joined = collect($this->monthlySeries(Member::query(), 'created_at', $months));
        $before = Member::where('created_at', '<', today()->startOfMonth()->subMonths($months - 1))->count();

        $running = $before;

        return $joined
            ->map(function (array $row) use (&$running) {
                $running += $row['total'];

                return ['month' => $row['month'], 'joined' => $row['total'], 'total' => $running];
            })
            ->all();
    }

    public function byGender(): array
    {
        return $this->breakdown(Member::query(), 'gender');
    }

    public function byStatus(): array
    {
        return $this->breakdown(Member::query(), 'status');
    }

    public function byBloodType(): array
    {
        return $this->breakdown(Member::whereNotNull('blood_type'), 'blood_type');
    }

    /** Ten-year bands, so the club can see who its programmes are aimed at. */
    public function byAgeBand(): array
    {
        return Member::whereNotNull('birth_date')
            ->get(['birth_date'])
            ->groupBy(function (Member $m) {
                $age = $m->birth_date->age;

                return match (true) {
                    $age < 18 => 'under_18',
                    $age < 30 => '18_29',
                    $age < 40 => '30_39',
                    $age < 50 => '40_49',
                    $age < 60 => '50_59',
                    default => '60_plus',
                };
            })
            ->map->count()
            ->map(fn (int $total, string $band) => ['label' => $band, 'total' => $total])
            ->values()
            ->all();
    }

    public function byJoinYear(): array
    {
        return Member::whereNotNull('joined_at')
            ->get(['joined_at'])
            ->groupBy(fn (Member $m) => $m->joined_at->format('Y'))
            ->map->count()
            ->map(fn (int $total, string $year) => ['label' => $year, 'total' => $total])
            ->sortBy('label')
            ->values()
            ->all();
    }

    public function inactive(int $days): array
    {
        return Member::active()
            ->whereDoesntHave('attendances', fn ($q) => $q->where('checked_in_at', '>=', now()->subDays($days)))
            ->withCount('attendances')
            ->with('activeMembership')
            ->orderBy('last_name')
            ->get()
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'phone' => $m->phone,
                'visits_ever' => $m->attendances_count,
                'last_visit' => $m->attendances()->latest('checked_in_at')->value('checked_in_at')?->toDateTimeString(),
                'expires_at' => $m->activeMembership?->ends_at?->toDateString(),
            ])
            ->all();
    }

    /** Paid, but never walked in — the clearest refund risk there is. */
    public function neverAttended(): array
    {
        return Member::doesntHave('attendances')
            ->has('memberships')
            ->with('activeMembership.plan')
            ->get()
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'phone' => $m->phone,
                'joined_at' => $m->joined_at?->toDateString(),
                'plan' => $m->activeMembership?->plan?->name,
            ])
            ->all();
    }

    public function withoutMembership(): array
    {
        return Member::active()
            ->whereDoesntHave('memberships', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'phone' => $m->phone,
                'last_membership' => $m->memberships()->latest('ends_at')->value('ends_at')?->toDateString(),
            ])
            ->all();
    }

    public function birthdaysThisMonth(): array
    {
        return Member::active()
            ->whereNotNull('birth_date')
            ->whereMonth('birth_date', today()->month)
            ->get()
            ->sortBy(fn (Member $m) => $m->birth_date->day)
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'phone' => $m->phone,
                'day' => $m->birth_date->day,
                'turns' => $m->birth_date->age + 1,
            ])
            ->values()
            ->all();
    }

    /** The health notes reception needs to know about before an emergency. */
    public function medicalNotes(): array
    {
        return Member::active()
            ->where(fn ($q) => $q->whereNotNull('diseases')->orWhereNotNull('allergies'))
            ->get()
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'blood_type' => $m->blood_type,
                'diseases' => $m->diseases,
                'allergies' => $m->allergies,
                'emergency_name' => $m->emergency_name,
                'emergency_phone' => $m->emergency_phone,
            ])
            ->all();
    }

    public function topByAttendance(int $days, int $limit = 25): array
    {
        return Member::withCount([
            'attendances' => fn ($q) => $q->where('checked_in_at', '>=', now()->subDays($days)),
        ])
            ->orderByDesc('attendances_count')
            ->limit($limit)
            ->get()
            ->filter(fn (Member $m) => $m->attendances_count > 0)
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'visits' => $m->attendances_count,
            ])
            ->all();
    }

    public function topBySpend($from, $to, int $limit = 25): array
    {
        return Payment::whereBetween('paid_at', [$from, $to])
            ->where('status', 'paid')
            ->whereNotNull('member_id')
            ->select('member_id', DB::raw('sum(amount) as total'), DB::raw('count(*) as payments'))
            ->groupBy('member_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->map(fn ($row) => [
                'code' => $row->member?->code,
                'name' => $row->member?->full_name,
                'payments' => (int) $row->payments,
                'total' => round((float) $row->total, 2),
            ])
            ->all();
    }

    /** Everything a member has ever paid, against how long they have stayed. */
    public function lifetimeValue(int $limit = 50): array
    {
        return Member::withSum(['payments as spend' => fn ($q) => $q->where('status', 'paid')], 'amount')
            ->withCount('attendances')
            ->orderByDesc('spend')
            ->limit($limit)
            ->get()
            ->map(function (Member $m) {
                $months = max(1, ($m->joined_at ?? $m->created_at)->diffInMonths(now()) ?: 1);

                return [
                    'code' => $m->code,
                    'name' => $m->full_name,
                    'months' => (int) $months,
                    'visits' => $m->attendances_count,
                    'total_spend' => round((float) ($m->spend ?? 0), 2),
                    'per_month' => round((float) ($m->spend ?? 0) / $months, 2),
                ];
            })
            ->all();
    }

    /**
     * Of the members who joined in each month, how many are still active
     * today. This is the number that tells a club whether it is growing.
     */
    public function retentionByCohort(int $months = 12): array
    {
        return Member::where('created_at', '>=', today()->startOfMonth()->subMonths($months - 1))
            ->get(['id', 'created_at', 'status'])
            ->groupBy(fn (Member $m) => $m->created_at->format('Y-m'))
            ->map(function ($cohort, string $month) {
                $retained = $cohort->where('status', 'active')->count();

                return [
                    'month' => $month,
                    'joined' => $cohort->count(),
                    'retained' => $retained,
                    'rate' => $cohort->count() ? round($retained / $cohort->count() * 100, 1) : 0.0,
                ];
            })
            ->sortBy('month')
            ->values()
            ->all();
    }
}
