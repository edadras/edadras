<?php

namespace App\Reports;

use App\Models\Attendance;
use App\Models\ClassBooking;
use App\Models\Member;
use App\Reports\Concerns\AggregatesRows;
use Illuminate\Support\Facades\DB;

/** When the club is busy, who comes and who books without turning up. */
class AttendanceReports
{
    use AggregatesRows;

    public function series(int $days): array
    {
        return $this->dailySeries(Attendance::query(), 'checked_in_at', $days);
    }

    public function byMonth(int $months = 12): array
    {
        return $this->monthlySeries(Attendance::query(), 'checked_in_at', $months);
    }

    public function byHour(int $days): array
    {
        $rows = Attendance::where('checked_in_at', '>=', today()->subDays($days))
            ->get(['checked_in_at'])
            ->groupBy(fn (Attendance $a) => $a->checked_in_at->format('H'))
            ->map->count();

        return collect(range(0, 23))
            ->map(function (int $hour) use ($rows) {
                $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

                return ['hour' => $key, 'total' => (int) ($rows[$key] ?? 0)];
            })
            ->all();
    }

    public function byWeekday(int $days): array
    {
        $rows = Attendance::where('checked_in_at', '>=', today()->subDays($days))
            ->get(['checked_in_at'])
            ->groupBy(fn (Attendance $a) => $a->checked_in_at->dayOfWeek);

        return collect(range(0, 6))
            ->map(fn (int $day) => [
                'weekday' => $day,
                'label' => today()->startOfWeek()->addDays($day)->format('l'),
                'total' => $rows->get($day)?->count() ?? 0,
            ])
            ->all();
    }

    public function byMethod(int $days): array
    {
        return $this->breakdown(
            Attendance::where('checked_in_at', '>=', today()->subDays($days)),
            'method'
        );
    }

    public function byGender(int $days): array
    {
        return Attendance::where('checked_in_at', '>=', today()->subDays($days))
            ->with('member:id,gender')
            ->get()
            ->groupBy(fn (Attendance $a) => $a->member?->gender ?? '—')
            ->map(fn ($rows, $gender) => ['label' => (string) $gender, 'total' => $rows->count()])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** Which member of staff let people in — the receptionist's own tally. */
    public function byStaff(int $days): array
    {
        return Attendance::where('checked_in_at', '>=', today()->subDays($days))
            ->whereNotNull('staff_id')
            ->with('staff:id,name')
            ->get()
            ->groupBy('staff_id')
            ->map(fn ($rows) => [
                'staff' => $rows->first()->staff?->name ?? '—',
                'total' => $rows->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public function detail($from, $to): array
    {
        return Attendance::whereBetween('checked_in_at', [$from, $to])
            ->with('member:id,code,first_name,last_name', 'staff:id,name')
            ->orderByDesc('checked_in_at')
            ->get()
            ->map(fn (Attendance $a) => [
                'code' => $a->member?->code,
                'member' => $a->member?->full_name,
                'checked_in_at' => $a->checked_in_at?->toDateTimeString(),
                'checked_out_at' => $a->checked_out_at?->toDateTimeString(),
                'minutes' => $a->checked_out_at ? $a->checked_in_at->diffInMinutes($a->checked_out_at) : null,
                'method' => $a->method,
                'device' => $a->device,
                'staff' => $a->staff?->name,
                'consumed_session' => (bool) $a->consumed_session,
            ])
            ->all();
    }

    public function insideNow(): array
    {
        return Attendance::stillInside()
            ->with('member:id,code,first_name,last_name,photo_path')
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn (Attendance $a) => [
                'code' => $a->member?->code,
                'member' => $a->member?->full_name,
                'since' => $a->checked_in_at?->toDateTimeString(),
                'minutes' => $a->checked_in_at?->diffInMinutes(now()),
            ])
            ->all();
    }

    /** How long a visit actually lasts, which is what sizes the changing rooms. */
    public function visitDuration($from, $to): array
    {
        $rows = Attendance::whereBetween('checked_in_at', [$from, $to])
            ->whereNotNull('checked_out_at')
            ->get(['checked_in_at', 'checked_out_at'])
            ->map(fn (Attendance $a) => $a->checked_in_at->diffInMinutes($a->checked_out_at));

        if ($rows->isEmpty()) {
            return ['visits' => 0, 'average_minutes' => 0, 'median_minutes' => 0, 'longest_minutes' => 0];
        }

        $sorted = $rows->sort()->values();

        return [
            'visits' => $rows->count(),
            'average_minutes' => (int) round($rows->avg()),
            'median_minutes' => (int) $sorted[intdiv($sorted->count(), 2)],
            'longest_minutes' => (int) $rows->max(),
            'shortest_minutes' => (int) $rows->min(),
        ];
    }

    public function longestVisits($from, $to, int $limit = 25): array
    {
        return Attendance::whereBetween('checked_in_at', [$from, $to])
            ->whereNotNull('checked_out_at')
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->map(fn (Attendance $a) => [
                'code' => $a->member?->code,
                'member' => $a->member?->full_name,
                'date' => $a->checked_in_at->toDateString(),
                'minutes' => $a->checked_in_at->diffInMinutes($a->checked_out_at),
            ])
            ->sortByDesc('minutes')
            ->take($limit)
            ->values()
            ->all();
    }

    public function visitsPerMember($from, $to): array
    {
        $rows = Attendance::whereBetween('checked_in_at', [$from, $to])
            ->select('member_id', DB::raw('count(*) as total'))
            ->groupBy('member_id')
            ->pluck('total');

        $activeMembers = Member::active()->count();

        return [
            'members_who_came' => $rows->count(),
            'active_members' => $activeMembers,
            'reach_percent' => $activeMembers ? round($rows->count() / $activeMembers * 100, 1) : 0.0,
            'visits' => (int) $rows->sum(),
            'average_visits' => $rows->count() ? round($rows->avg(), 1) : 0.0,
        ];
    }

    public function peakDays($from, $to, int $limit = 15): array
    {
        return Attendance::whereBetween('checked_in_at', [$from, $to])
            ->select(DB::raw('date(checked_in_at) as day'), DB::raw('count(*) as total'))
            ->groupBy('day')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['date' => $row->day, 'total' => (int) $row->total])
            ->all();
    }

    /** Somebody's very first visit — the moment a sale becomes a member. */
    public function firstVisits($from, $to): array
    {
        return Attendance::whereBetween('checked_in_at', [$from, $to])
            ->with('member:id,code,first_name,last_name,joined_at')
            ->get()
            ->groupBy('member_id')
            ->filter(fn ($rows, $memberId) => Attendance::where('member_id', $memberId)
                ->where('checked_in_at', '<', $rows->min('checked_in_at'))
                ->doesntExist())
            ->map(fn ($rows) => [
                'code' => $rows->first()->member?->code,
                'member' => $rows->first()->member?->full_name,
                'joined_at' => $rows->first()->member?->joined_at?->toDateString(),
                'first_visit' => $rows->min('checked_in_at')->toDateTimeString(),
            ])
            ->values()
            ->all();
    }

    /** Booked a class and never showed up. */
    public function noShows($from, $to): array
    {
        return ClassBooking::whereBetween('booked_at', [$from, $to])
            ->where('status', 'booked')
            ->whereHas('session', fn ($q) => $q->where('ends_at', '<', now()))
            ->with('member:id,code,first_name,last_name', 'session.gymClass:id,name')
            ->get()
            ->map(fn (ClassBooking $b) => [
                'code' => $b->member?->code,
                'member' => $b->member?->full_name,
                'class' => $b->session?->gymClass?->name,
                'session_at' => $b->session?->starts_at?->toDateTimeString(),
            ])
            ->all();
    }
}
