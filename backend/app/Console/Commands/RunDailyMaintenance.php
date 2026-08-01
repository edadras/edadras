<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Membership;
use App\Models\Tenant;
use App\Notifications\MembershipExpiring;
use App\Services\Ai\AiInsightService;
use App\Services\MembershipService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The nightly sweep, run per club: expire what ran out, close entries left
 * open, send renewal reminders and refresh the AI insights.
 */
class RunDailyMaintenance extends Command
{
    protected $signature = 'gymflow:maintenance
                            {--tenant= : Limit the run to one club id}
                            {--skip-insights : Do not regenerate AI insights}';

    protected $description = 'Expire memberships, close open check-ins, send renewal reminders and refresh insights.';

    public function handle(
        TenantContext $tenancy,
        MembershipService $memberships,
        AiInsightService $insights,
    ): int {
        $clubs = Tenant::query()
            ->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        foreach ($clubs as $club) {
            $tenancy->run($club, function () use ($club, $memberships, $insights) {
                $expired = $memberships->expireOverdue();
                $closed = $this->closeAbandonedCheckIns();
                $reminded = $this->sendRenewalReminders();

                if (! $this->option('skip-insights')) {
                    $insights->refresh();
                }

                $this->line("[{$club->slug}] expired: {$expired}, closed: {$closed}, reminded: {$reminded}");
            });
        }

        return self::SUCCESS;
    }

    /** Someone who never scanned out should not stay "inside" for days. */
    protected function closeAbandonedCheckIns(): int
    {
        $cutoff = now()->subHours((int) config('gymflow.checkin.auto_checkout_after_hours', 6));

        return Attendance::stillInside()
            ->where('checked_in_at', '<', $cutoff)
            ->update([
                'checked_out_at' => now(),
                'notes' => 'auto-closed',
            ]);
    }

    /**
     * Notifies members whose pass ends on one of the configured days out, or
     * whose remaining sessions dropped to a configured threshold.
     */
    protected function sendRenewalReminders(): int
    {
        $days = (array) config('gymflow.renewal.remind_days_before', [7, 3, 1]);
        $sessionThresholds = (array) config('gymflow.renewal.remind_sessions_left', [3, 1]);

        $byDate = Membership::active()
            ->whereNotNull('ends_at')
            ->whereIn('ends_at', collect($days)->map(fn (int $d) => today()->addDays($d)->toDateString()))
            ->with('member.user')
            ->get();

        $bySessions = Membership::active()
            ->whereIn('remaining_sessions', $sessionThresholds)
            ->with('member.user')
            ->get();

        $sent = 0;

        foreach ($byDate->merge($bySessions)->unique('id') as $membership) {
            $user = $membership->member?->user;

            if (! $user) {
                continue;
            }

            $user->notify(new MembershipExpiring($membership));
            $sent++;
        }

        return $sent;
    }
}
