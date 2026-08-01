<?php

namespace App\Reports;

use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * Every report the platform can run, in one place. A key maps to the group it
 * belongs to, the parameters the screen should offer and the closure that
 * produces the rows, so adding a report is one entry rather than three edits
 * across the controller, the catalogue and the service.
 */
class ReportRegistry
{
    /** Parameters a report understands, so the UI knows what to render. */
    public const PARAM_RANGE = 'range';      // from + to

    public const PARAM_DAYS = 'days';        // a rolling window

    public const PARAM_MONTHS = 'months';    // a rolling window in months

    public const PARAM_DATE = 'date';        // one specific day

    public function __construct(
        private readonly ReportService $overview,
        private readonly MemberReports $members,
        private readonly MembershipReports $memberships,
        private readonly AttendanceReports $attendance,
        private readonly ClassReports $classes,
        private readonly CoachReports $coaches,
        private readonly FinanceReports $finance,
        private readonly ShopReports $shop,
        private readonly TrainingReports $training,
        private readonly EngagementReports $engagement,
        private readonly AdminReports $admin,
    ) {}

    /**
     * @return array<string, array{group:string, params:array<int,string>, run:callable}>
     */
    public function definitions(): array
    {
        return array_merge(
            $this->overviewReports(),
            $this->memberReports(),
            $this->membershipReports(),
            $this->attendanceReports(),
            $this->classReports(),
            $this->coachReports(),
            $this->financeReports(),
            $this->shopReports(),
            $this->trainingReports(),
            $this->engagementReports(),
            $this->adminReports(),
        );
    }

    /** The list the reports screen shows, without running anything. */
    public function catalogue(): array
    {
        return collect($this->definitions())
            ->map(fn (array $definition, string $key) => [
                'key' => $key,
                'group' => $definition['group'],
                'params' => $definition['params'],
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function groups(): array
    {
        return collect($this->definitions())->pluck('group')->unique()->values()->all();
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /** Runs one report. `$params` carries whatever the screen selected. */
    public function run(string $key, ReportParams $params): mixed
    {
        $definition = $this->definitions()[$key] ?? null;

        abort_unless($definition, 404, __('reports.unknown'));

        return ($definition['run'])($params);
    }

    // ------------------------------------------------------------- overview

    protected function overviewReports(): array
    {
        return [
            'dashboard' => $this->report('overview', [], fn () => $this->overview->dashboard()),
            'today_at_a_glance' => $this->report('overview', [], fn () => $this->finance->dailyRegister(today())),
            'month_to_date' => $this->report('overview', [], fn () => $this->finance->profitAndLoss(today()->startOfMonth(), today()->endOfDay())),
            'year_to_date' => $this->report('overview', [], fn () => $this->finance->profitAndLoss(today()->startOfYear(), today()->endOfDay())),
            'kpi_summary' => $this->report('overview', [self::PARAM_RANGE], fn (ReportParams $p) => [
                'profit_and_loss' => $this->finance->profitAndLoss($p->from, $p->to),
                'visits' => $this->attendance->visitsPerMember($p->from, $p->to),
                'transaction_value' => $this->finance->averageTransactionValue($p->from, $p->to),
                'sessions_outstanding' => $this->memberships->sessionsRemaining(),
            ]),
        ];
    }

    // -------------------------------------------------------------- members

    protected function memberReports(): array
    {
        return [
            'member_directory' => $this->report('members', [], fn () => $this->members->directory()),
            'new_members_by_day' => $this->report('members', [self::PARAM_DAYS], fn ($p) => $this->members->newByDay($p->days)),
            'new_members_by_month' => $this->report('members', [self::PARAM_MONTHS], fn ($p) => $this->members->newByMonth($p->months)),
            'member_growth' => $this->report('members', [self::PARAM_MONTHS], fn ($p) => $this->members->growth($p->months)),
            'members_by_gender' => $this->report('members', [], fn () => $this->members->byGender()),
            'members_by_status' => $this->report('members', [], fn () => $this->members->byStatus()),
            'members_by_age_band' => $this->report('members', [], fn () => $this->members->byAgeBand()),
            'members_by_blood_type' => $this->report('members', [], fn () => $this->members->byBloodType()),
            'members_by_join_year' => $this->report('members', [], fn () => $this->members->byJoinYear()),
            'inactive_members' => $this->report('members', [self::PARAM_DAYS], fn ($p) => $this->members->inactive($p->days)),
            'never_attended_members' => $this->report('members', [], fn () => $this->members->neverAttended()),
            'members_without_membership' => $this->report('members', [], fn () => $this->members->withoutMembership()),
            'birthdays_this_month' => $this->report('members', [], fn () => $this->members->birthdaysThisMonth()),
            'members_medical_notes' => $this->report('members', [], fn () => $this->members->medicalNotes()),
            'top_members_by_attendance' => $this->report('members', [self::PARAM_DAYS], fn ($p) => $this->members->topByAttendance($p->days, $p->limit)),
            'top_members_by_spend' => $this->report('members', [self::PARAM_RANGE], fn ($p) => $this->members->topBySpend($p->from, $p->to, $p->limit)),
            'member_lifetime_value' => $this->report('members', [], fn ($p) => $this->members->lifetimeValue($p->limit)),
            'member_retention_cohorts' => $this->report('members', [self::PARAM_MONTHS], fn ($p) => $this->members->retentionByCohort($p->months)),
        ];
    }

    // ---------------------------------------------------------- memberships

    protected function membershipReports(): array
    {
        return [
            'active_memberships' => $this->report('memberships', [], fn () => $this->memberships->active()),
            'expiring_memberships' => $this->report('memberships', [self::PARAM_DAYS], fn ($p) => $this->memberships->expiring($p->days)),
            'expired_memberships' => $this->report('memberships', [self::PARAM_RANGE], fn ($p) => $this->memberships->expired($p->from, $p->to)),
            'frozen_memberships' => $this->report('memberships', [], fn () => $this->memberships->frozen()),
            'cancelled_memberships' => $this->report('memberships', [self::PARAM_RANGE], fn ($p) => $this->memberships->cancelled($p->from, $p->to)),
            'memberships_by_status' => $this->report('memberships', [], fn () => $this->memberships->byStatus()),
            'memberships_by_type' => $this->report('memberships', [], fn () => $this->memberships->byType()),
            'membership_mix' => $this->report('memberships', [], fn () => $this->memberships->mix()),
            'plan_popularity' => $this->report('memberships', [self::PARAM_RANGE], fn ($p) => $this->memberships->planPopularity($p->from, $p->to)),
            'plan_revenue' => $this->report('memberships', [self::PARAM_RANGE], fn ($p) => $this->memberships->planRevenue($p->from, $p->to)),
            'plan_catalogue' => $this->report('memberships', [], fn () => $this->memberships->averageDuration()),
            'memberships_sold_by_day' => $this->report('memberships', [self::PARAM_DAYS], fn ($p) => $this->memberships->soldByDay($p->days)),
            'memberships_sold_by_month' => $this->report('memberships', [self::PARAM_MONTHS], fn ($p) => $this->memberships->soldByMonth($p->months)),
            'membership_revenue_by_month' => $this->report('memberships', [self::PARAM_MONTHS], fn ($p) => $this->memberships->revenueByMonth($p->months)),
            'renewal_rate' => $this->report('memberships', [self::PARAM_MONTHS], fn ($p) => $this->memberships->renewalRate($p->months)),
            'sessions_remaining' => $this->report('memberships', [], fn () => $this->memberships->sessionsRemaining()),
            'unused_session_liability' => $this->report('memberships', [], fn () => $this->memberships->unusedSessionLiability()),
            'membership_balances_due' => $this->report('memberships', [], fn () => $this->memberships->outstandingBalances()),
        ];
    }

    // ----------------------------------------------------------- attendance

    protected function attendanceReports(): array
    {
        return [
            'attendance_series' => $this->report('attendance', [self::PARAM_DAYS], fn ($p) => $this->attendance->series($p->days)),
            'attendance_by_month' => $this->report('attendance', [self::PARAM_MONTHS], fn ($p) => $this->attendance->byMonth($p->months)),
            'attendance_by_hour' => $this->report('attendance', [self::PARAM_DAYS], fn ($p) => $this->attendance->byHour($p->days)),
            'attendance_by_weekday' => $this->report('attendance', [self::PARAM_DAYS], fn ($p) => $this->attendance->byWeekday($p->days)),
            'attendance_by_method' => $this->report('attendance', [self::PARAM_DAYS], fn ($p) => $this->attendance->byMethod($p->days)),
            'attendance_by_gender' => $this->report('attendance', [self::PARAM_DAYS], fn ($p) => $this->attendance->byGender($p->days)),
            'attendance_by_staff' => $this->report('attendance', [self::PARAM_DAYS], fn ($p) => $this->attendance->byStaff($p->days)),
            'attendance_detail' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->detail($p->from, $p->to)),
            'inside_now' => $this->report('attendance', [], fn () => $this->attendance->insideNow()),
            'visit_duration' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->visitDuration($p->from, $p->to)),
            'longest_visits' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->longestVisits($p->from, $p->to, $p->limit)),
            'visits_per_member' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->visitsPerMember($p->from, $p->to)),
            'peak_days' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->peakDays($p->from, $p->to, $p->limit)),
            'first_visits' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->firstVisits($p->from, $p->to)),
            'class_no_shows' => $this->report('attendance', [self::PARAM_RANGE], fn ($p) => $this->attendance->noShows($p->from, $p->to)),
        ];
    }

    // -------------------------------------------------------------- classes

    protected function classReports(): array
    {
        return [
            'class_roster' => $this->report('classes', [], fn () => $this->classes->roster()),
            'class_schedule' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->schedule($p->from, $p->to)),
            'class_occupancy' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->occupancy($p->from, $p->to)),
            'full_sessions' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->fullSessions($p->from, $p->to)),
            'empty_sessions' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->emptySessions($p->from, $p->to)),
            'sessions_by_kind' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->byKind($p->from, $p->to)),
            'pool_sessions' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->poolSessions($p->from, $p->to)),
            'bookings_by_day' => $this->report('classes', [self::PARAM_DAYS], fn ($p) => $this->classes->bookingsByDay($p->days)),
            'bookings_by_status' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->bookingsByStatus($p->from, $p->to)),
            'cancelled_bookings' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->cancelledBookings($p->from, $p->to)),
            'bookings_by_member' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->bookingsByMember($p->from, $p->to, $p->limit)),
            'class_revenue' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->revenue($p->from, $p->to)),
            'attendance_vs_bookings' => $this->report('classes', [self::PARAM_RANGE], fn ($p) => $this->classes->attendanceVsBookings($p->from, $p->to)),
        ];
    }

    // --------------------------------------------------------------- coaches

    protected function coachReports(): array
    {
        return [
            'coach_roster' => $this->report('coaches', [], fn () => $this->coaches->roster()),
            'coach_performance' => $this->report('coaches', [self::PARAM_RANGE], fn ($p) => $this->coaches->performance($p->from, $p->to)),
            'coach_sessions_by_month' => $this->report('coaches', [self::PARAM_MONTHS], fn ($p) => $this->coaches->sessionsByMonth($p->months)),
            'coach_salary_cost' => $this->report('coaches', [self::PARAM_RANGE], fn ($p) => $this->coaches->salaryCost($p->from, $p->to)),
            'students_per_coach' => $this->report('coaches', [], fn () => $this->coaches->studentsPerCoach()),
            'plans_written_by_coach' => $this->report('coaches', [self::PARAM_RANGE], fn ($p) => $this->coaches->plansWritten($p->from, $p->to)),
            'coaches_by_contract' => $this->report('coaches', [], fn () => $this->coaches->byContractType()),
            'coaches_by_status' => $this->report('coaches', [], fn () => $this->coaches->byStatus()),
        ];
    }

    // --------------------------------------------------------------- finance

    protected function financeReports(): array
    {
        return [
            'daily_register' => $this->report('finance', [self::PARAM_DATE], fn ($p) => $this->finance->dailyRegister($p->date)),
            'profit_and_loss' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->profitAndLoss($p->from, $p->to)),
            'revenue_by_category' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->incomeByCategory($p->from, $p->to)),
            'expense_by_category' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->expenseByCategory($p->from, $p->to)),
            'revenue_by_method' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->incomeByMethod($p->from, $p->to)),
            'expense_by_method' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->expenseByMethod($p->from, $p->to)),
            'revenue_series' => $this->report('finance', [self::PARAM_DAYS], fn ($p) => $this->finance->incomeSeries($p->days)),
            'expense_series' => $this->report('finance', [self::PARAM_DAYS], fn ($p) => $this->finance->expenseSeries($p->days)),
            'cash_flow' => $this->report('finance', [self::PARAM_DAYS], fn ($p) => $this->finance->cashFlow($p->days)),
            'revenue_by_month' => $this->report('finance', [self::PARAM_MONTHS], fn ($p) => $this->finance->incomeByMonth($p->months)),
            'expense_by_month' => $this->report('finance', [self::PARAM_MONTHS], fn ($p) => $this->finance->expenseByMonth($p->months)),
            'transaction_log' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->transactions($p->from, $p->to)),
            'invoices_issued' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->invoices($p->from, $p->to)),
            'invoices_by_status' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->invoicesByStatus($p->from, $p->to)),
            'outstanding_invoices' => $this->report('finance', [], fn () => $this->finance->outstandingInvoices()),
            'paid_invoices' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->paidInvoices($p->from, $p->to)),
            'payment_log' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->payments($p->from, $p->to)),
            'payments_by_method' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->paymentsByMethod($p->from, $p->to)),
            'payments_by_gateway' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->paymentsByGateway($p->from, $p->to)),
            'payments_by_day' => $this->report('finance', [self::PARAM_DAYS], fn ($p) => $this->finance->paymentsByDay($p->days)),
            'refunds_and_failures' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->refunds($p->from, $p->to)),
            'average_transaction_value' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->averageTransactionValue($p->from, $p->to)),
            'revenue_per_member' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->revenuePerMember($p->from, $p->to, $p->limit)),
            'discounts_given' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->discounts($p->from, $p->to)),
            'wallet_balances' => $this->report('finance', [], fn () => $this->finance->walletBalances()),
            'wallet_movements' => $this->report('finance', [self::PARAM_RANGE], fn ($p) => $this->finance->walletMovements($p->from, $p->to)),
            'wallet_liability' => $this->report('finance', [], fn () => $this->finance->walletLiability()),
        ];
    }

    // ------------------------------------------------------------------ shop

    protected function shopReports(): array
    {
        return [
            'product_catalogue' => $this->report('shop', [], fn () => $this->shop->catalogue()),
            'products_by_category' => $this->report('shop', [], fn () => $this->shop->byCategory()),
            'product_sales' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->sales($p->from, $p->to)),
            'best_sellers' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->bestSellers($p->from, $p->to, $p->limit)),
            'dead_stock' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->deadStock($p->from, $p->to)),
            'low_stock' => $this->report('shop', [], fn () => $this->shop->lowStock()),
            'inventory_valuation' => $this->report('shop', [], fn () => $this->shop->inventoryValuation()),
            'shop_sales_by_day' => $this->report('shop', [self::PARAM_DAYS], fn ($p) => $this->shop->salesByDay($p->days)),
            'gross_margin' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->grossMargin($p->from, $p->to)),
            'stock_movements' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->stockMovements($p->from, $p->to)),
            'stock_adjustments' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->stockAdjustments($p->from, $p->to)),
            'stock_movements_by_type' => $this->report('shop', [self::PARAM_RANGE], fn ($p) => $this->shop->movementsByType($p->from, $p->to)),
        ];
    }

    // -------------------------------------------------------------- training

    protected function trainingReports(): array
    {
        return [
            'active_workout_plans' => $this->report('training', [], fn () => $this->training->activeWorkoutPlans()),
            'active_nutrition_plans' => $this->report('training', [], fn () => $this->training->activeNutritionPlans()),
            'workout_plans_by_status' => $this->report('training', [], fn () => $this->training->workoutPlansByStatus()),
            'nutrition_plans_by_status' => $this->report('training', [], fn () => $this->training->nutritionPlansByStatus()),
            'ai_program_share' => $this->report('training', [], fn () => $this->training->aiShare()),
            'members_without_program' => $this->report('training', [], fn () => $this->training->membersWithoutProgram()),
            'measurements_log' => $this->report('training', [self::PARAM_RANGE], fn ($p) => $this->training->measurementsLog($p->from, $p->to)),
            'weight_change' => $this->report('training', [], fn ($p) => $this->training->weightChange($p->limit)),
            'body_fat_change' => $this->report('training', [], fn ($p) => $this->training->bodyFatChange($p->limit)),
            'muscle_change' => $this->report('training', [], fn ($p) => $this->training->muscleChange($p->limit)),
            'bmi_distribution' => $this->report('training', [], fn () => $this->training->bmiDistribution()),
            'exercise_usage' => $this->report('training', [], fn ($p) => $this->training->exerciseUsage($p->limit)),
            'exercises_by_muscle_group' => $this->report('training', [], fn () => $this->training->exercisesByMuscleGroup()),
        ];
    }

    // ------------------------------------------------------------ engagement

    protected function engagementReports(): array
    {
        return [
            'campaign_log' => $this->report('engagement', [self::PARAM_RANGE], fn ($p) => $this->engagement->campaigns($p->from, $p->to)),
            'campaigns_by_channel' => $this->report('engagement', [self::PARAM_RANGE], fn ($p) => $this->engagement->campaignsByChannel($p->from, $p->to)),
            'campaigns_by_status' => $this->report('engagement', [self::PARAM_RANGE], fn ($p) => $this->engagement->campaignsByStatus($p->from, $p->to)),
            'campaign_reach' => $this->report('engagement', [self::PARAM_RANGE], fn ($p) => $this->engagement->campaignReach($p->from, $p->to)),
            'chat_volume' => $this->report('engagement', [self::PARAM_DAYS], fn ($p) => $this->engagement->chatVolume($p->days)),
            'chat_by_sender' => $this->report('engagement', [self::PARAM_RANGE], fn ($p) => $this->engagement->chatBySender($p->from, $p->to)),
            'unanswered_threads' => $this->report('engagement', [], fn () => $this->engagement->unansweredThreads()),
            'unread_messages' => $this->report('engagement', [], fn () => $this->engagement->unreadMessages()),
            'push_coverage' => $this->report('engagement', [], fn () => $this->engagement->pushCoverage()),
            'ai_insights' => $this->report('engagement', [], fn () => $this->engagement->aiInsights()),
            'ai_insights_by_severity' => $this->report('engagement', [], fn () => $this->engagement->aiInsightsBySeverity()),
        ];
    }

    // ----------------------------------------------------------------- admin

    protected function adminReports(): array
    {
        return [
            'staff_roster' => $this->report('admin', [], fn () => $this->admin->staffRoster()),
            'roles_matrix' => $this->report('admin', [], fn () => $this->admin->rolesMatrix()),
            'staff_by_role' => $this->report('admin', [], fn () => $this->admin->staffByRole()),
            'dormant_accounts' => $this->report('admin', [self::PARAM_DAYS], fn ($p) => $this->admin->dormantAccounts($p->days)),
            'audit_activity' => $this->report('admin', [self::PARAM_RANGE], fn ($p) => $this->admin->auditActivity($p->from, $p->to)),
            'audit_by_action' => $this->report('admin', [self::PARAM_RANGE], fn ($p) => $this->admin->auditByAction($p->from, $p->to)),
            'audit_by_user' => $this->report('admin', [self::PARAM_RANGE], fn ($p) => $this->admin->auditByUser($p->from, $p->to)),
            'login_history' => $this->report('admin', [self::PARAM_RANGE], fn ($p) => $this->admin->loginHistory($p->from, $p->to)),
            'failed_logins' => $this->report('admin', [self::PARAM_RANGE], fn ($p) => $this->admin->failedLogins($p->from, $p->to)),
            'data_changes' => $this->report('admin', [self::PARAM_RANGE], fn ($p) => $this->admin->dataChanges($p->from, $p->to)),
        ];
    }

    /** @param array<int, string> $params */
    protected function report(string $group, array $params, callable $run): array
    {
        return ['group' => $group, 'params' => $params, 'run' => $run];
    }
}

