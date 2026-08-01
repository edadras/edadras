<?php

namespace App\Services\Ai;

use App\Models\AiInsight;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Transaction;
use App\Services\ReportService;
use Illuminate\Support\Collection;

/**
 * The analytical half of the AI module. Churn risk, revenue forecasting and
 * attendance analysis are computed from the club's own data, so they keep
 * working with no API key configured.
 */
class AiInsightService
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Scores how likely each active member is to walk away, from 0 to 100.
     * The signals are days since the last visit, how the recent visit rate
     * compares with the member's own baseline, and how close the membership
     * is to running out.
     */
    public function churnRisk(int $limit = 50): Collection
    {
        $members = Member::active()
            ->with(['activeMembership', 'attendances' => fn ($q) => $q->where('checked_in_at', '>=', now()->subDays(90))])
            ->get();

        return $members
            ->map(function (Member $member) {
                $visits = $member->attendances;
                $last = $visits->max('checked_in_at');
                $daysSince = $last ? (int) $last->diffInDays(now()) : 90;

                $recent = $visits->where('checked_in_at', '>=', now()->subDays(30))->count();
                $previous = $visits
                    ->where('checked_in_at', '>=', now()->subDays(60))
                    ->where('checked_in_at', '<', now()->subDays(30))
                    ->count();

                $score = 0;

                // Absence is the strongest signal we have, and on its own it
                // has to be able to reach the critical band: someone who has
                // not walked in for two months has effectively left.
                $score += min(60, $daysSince * 2.5);

                // A member visiting half as often as last month is drifting.
                if ($previous > 0) {
                    $drop = max(0, ($previous - $recent) / $previous);
                    $score += $drop * 25;
                } elseif ($recent === 0) {
                    $score += 15;
                }

                // An expiring membership that nobody renewed yet.
                $membership = $member->activeMembership;
                $daysLeft = $membership?->daysRemaining();

                if ($daysLeft !== null && $daysLeft <= 14) {
                    $score += (14 - $daysLeft) / 14 * 15;
                }

                if ($membership === null) {
                    $score += 20;
                }

                $score = (float) min(100, round($score, 1));

                return [
                    'member' => $member,
                    'score' => $score,
                    'severity' => $score >= 70 ? 'critical' : ($score >= 45 ? 'warning' : 'info'),
                    'days_since_last_visit' => $daysSince,
                    'visits_last_30_days' => $recent,
                    'visits_previous_30_days' => $previous,
                    'days_remaining' => $daysLeft,
                    'reasons' => $this->churnReasons($daysSince, $recent, $previous, $daysLeft, $membership),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Straight line projection of the month's revenue from the pace so far,
     * plus what the memberships expiring this month are worth if renewed.
     */
    public function revenueForecast(): array
    {
        $monthStart = today()->startOfMonth();
        $monthEnd = today()->endOfMonth();
        $daysElapsed = max(1, (int) $monthStart->diffInDays(today()) + 1);
        $daysInMonth = (int) $monthStart->daysInMonth;

        $earned = $this->reports->revenueBetween($monthStart, now());
        $dailyAverage = $earned / $daysElapsed;
        $projected = round($dailyAverage * $daysInMonth, 2);

        $lastMonthStart = $monthStart->copy()->subMonth();
        $lastMonth = $this->reports->revenueBetween($lastMonthStart, $lastMonthStart->copy()->endOfMonth());

        $renewalPipeline = (float) Membership::query()
            ->where('status', Membership::ACTIVE)
            ->whereBetween('ends_at', [today(), $monthEnd])
            ->sum('price');

        return [
            'month' => $monthStart->format('Y-m'),
            'earned_so_far' => round($earned, 2),
            'daily_average' => round($dailyAverage, 2),
            'projected_total' => $projected,
            'last_month_total' => round($lastMonth, 2),
            'change_percent' => $lastMonth > 0 ? round(($projected - $lastMonth) / $lastMonth * 100, 1) : null,
            'renewal_pipeline' => round($renewalPipeline, 2),
            'expense_so_far' => round($this->reports->expenseBetween($monthStart, now()), 2),
        ];
    }

    /** Where the crowds are, so staffing and class times can follow them. */
    public function attendanceAnalysis(): array
    {
        $byHour = collect($this->reports->attendanceByHour());
        $series = $this->reports->attendanceSeries(30);
        $peak = $byHour->sortByDesc('total')->first();
        $quiet = $byHour->where('total', '>', 0)->sortBy('total')->first();

        $firstHalf = $series->take(15)->sum('total');
        $secondHalf = $series->slice(15)->sum('total');

        return [
            'total_last_30_days' => (int) $series->sum('total'),
            'daily_average' => round($series->avg('total'), 1),
            'peak_hour' => $peak['hour'] ?? null,
            'peak_hour_visits' => $peak['total'] ?? 0,
            'quietest_hour' => $quiet['hour'] ?? null,
            'trend' => match (true) {
                $firstHalf === 0 => 'flat',
                $secondHalf > $firstHalf * 1.1 => 'rising',
                $secondHalf < $firstHalf * 0.9 => 'falling',
                default => 'flat',
            },
            'trend_percent' => $firstHalf > 0 ? round(($secondHalf - $firstHalf) / $firstHalf * 100, 1) : null,
            'by_hour' => $byHour->all(),
        ];
    }

    /** Ready made campaigns the manager can fire from the CRM screen. */
    public function campaignSuggestions(): array
    {
        $suggestions = [];

        $expiring = $this->reports->expiringMemberships(7);

        if ($expiring->isNotEmpty()) {
            $suggestions[] = [
                'key' => 'expiring_soon',
                'audience' => ['type' => 'expiring', 'days' => 7],
                'recipients' => $expiring->count(),
                'channel' => 'sms',
                'title' => __('ai.campaign_expiring_title'),
                'body' => __('ai.campaign_expiring_body'),
                'expected_value' => round($expiring->sum('price'), 2),
            ];
        }

        $inactive = $this->reports->inactiveMembers(30);

        if ($inactive->isNotEmpty()) {
            $suggestions[] = [
                'key' => 'win_back',
                'audience' => ['type' => 'inactive', 'days' => 30],
                'recipients' => $inactive->count(),
                'channel' => 'push',
                'title' => __('ai.campaign_winback_title'),
                'body' => __('ai.campaign_winback_body'),
            ];
        }

        $birthdays = Member::active()
            ->whereNotNull('birth_date')
            ->whereMonth('birth_date', today()->month)
            ->get();

        if ($birthdays->isNotEmpty()) {
            $suggestions[] = [
                'key' => 'birthday',
                'audience' => ['type' => 'birthday', 'month' => today()->month],
                'recipients' => $birthdays->count(),
                'channel' => 'push',
                'title' => __('ai.campaign_birthday_title'),
                'body' => __('ai.campaign_birthday_body'),
            ];
        }

        $quiet = collect($this->attendanceAnalysis()['by_hour'])
            ->filter(fn ($row) => $row['hour'] >= '08' && $row['hour'] <= '20')
            ->sortBy('total')
            ->first();

        if ($quiet) {
            $suggestions[] = [
                'key' => 'off_peak_discount',
                'audience' => ['type' => 'all'],
                'recipients' => Member::active()->count(),
                'channel' => 'push',
                'title' => __('ai.campaign_offpeak_title'),
                'body' => __('ai.campaign_offpeak_body', ['hour' => $quiet['hour']]),
            ];
        }

        return $suggestions;
    }

    /** Persists the current picture so the dashboard can show it instantly. */
    public function refresh(): int
    {
        $stored = 0;

        foreach ($this->churnRisk(30) as $risk) {
            if ($risk['score'] < 45) {
                continue;
            }

            AiInsight::updateOrCreate(
                [
                    'type' => 'churn_risk',
                    'subject_type' => Member::class,
                    'subject_id' => $risk['member']->id,
                ],
                [
                    'title' => $risk['member']->full_name,
                    'summary' => implode(' ', $risk['reasons']),
                    'payload' => collect($risk)->except('member')->all(),
                    'score' => $risk['score'],
                    'severity' => $risk['severity'],
                    'generated_at' => now(),
                    'dismissed_at' => null,
                ],
            );
            $stored++;
        }

        $forecast = $this->revenueForecast();

        AiInsight::updateOrCreate(
            ['type' => 'revenue_forecast', 'subject_type' => null, 'subject_id' => null],
            [
                'title' => __('ai.revenue_forecast'),
                'summary' => __('ai.revenue_forecast_summary', ['amount' => number_format($forecast['projected_total'])]),
                'payload' => $forecast,
                'score' => null,
                'severity' => ($forecast['change_percent'] ?? 0) < -10 ? 'warning' : 'info',
                'generated_at' => now(),
            ],
        );
        $stored++;

        AiInsight::updateOrCreate(
            ['type' => 'attendance_analysis', 'subject_type' => null, 'subject_id' => null],
            [
                'title' => __('ai.attendance_analysis'),
                'payload' => $this->attendanceAnalysis(),
                'generated_at' => now(),
                'severity' => 'info',
            ],
        );
        $stored++;

        foreach ($this->campaignSuggestions() as $suggestion) {
            AiInsight::updateOrCreate(
                ['type' => 'campaign_suggestion', 'subject_type' => null, 'subject_id' => null, 'title' => $suggestion['title']],
                [
                    'summary' => $suggestion['body'],
                    'payload' => $suggestion,
                    'generated_at' => now(),
                    'severity' => 'info',
                ],
            );
            $stored++;
        }

        return $stored;
    }

    /** A compact snapshot handed to the chat assistant as context. */
    public function snapshot(): array
    {
        $dashboard = $this->reports->dashboard();

        return [
            'members' => $dashboard['members'],
            'attendance' => $dashboard['attendance'],
            'revenue' => $dashboard['revenue'],
            'memberships' => $dashboard['memberships'],
            'forecast' => $this->revenueForecast(),
            'attendance_analysis' => collect($this->attendanceAnalysis())->except('by_hour')->all(),
            'top_expense_categories' => Transaction::expense()
                ->where('occurred_at', '>=', today()->startOfMonth())
                ->get()
                ->groupBy('category')
                ->map(fn ($rows) => round($rows->sum('amount'), 2))
                ->sortDesc()
                ->take(5)
                ->all(),
            'members_at_risk' => $this->churnRisk(5)->map(fn ($risk) => [
                'name' => $risk['member']->full_name,
                'score' => $risk['score'],
                'days_since_last_visit' => $risk['days_since_last_visit'],
            ])->all(),
        ];
    }

    /** @return array<int, string> */
    protected function churnReasons(int $daysSince, int $recent, int $previous, ?int $daysLeft, ?Membership $membership): array
    {
        $reasons = [];

        if ($daysSince >= 14) {
            $reasons[] = __('ai.reason_absent', ['days' => $daysSince]);
        }

        if ($previous > 0 && $recent < $previous) {
            $reasons[] = __('ai.reason_declining', ['from' => $previous, 'to' => $recent]);
        }

        if ($membership === null) {
            $reasons[] = __('ai.reason_no_membership');
        } elseif ($daysLeft !== null && $daysLeft <= 14) {
            $reasons[] = __('ai.reason_expiring', ['days' => $daysLeft]);
        }

        if ($membership?->remaining_sessions !== null && $membership->remaining_sessions <= 3) {
            $reasons[] = __('ai.reason_sessions_low', ['count' => $membership->remaining_sessions]);
        }

        return $reasons;
    }
}
