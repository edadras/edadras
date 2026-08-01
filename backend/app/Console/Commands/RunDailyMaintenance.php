<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Setting;
use App\Models\Tenant;
use App\Notifications\HappyBirthday;
use App\Notifications\MembershipExpiring;
use App\Services\Ai\AiInsightService;
use App\Services\MembershipService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
                $birthdays = $this->sendBirthdayGreetings();

                if (! $this->option('skip-insights')) {
                    $insights->refresh();
                }

                $this->line("[{$club->slug}] expired: {$expired}, closed: {$closed}, reminded: {$reminded}, birthdays: {$birthdays}");
            });
        }

        return self::SUCCESS;
    }

    /**
     * Wishes every active member whose birthday is today, once. The club can
     * attach a discount from its settings; without one it is just a message.
     */
    protected function sendBirthdayGreetings(): int
    {
        if (! config('gymflow.renewal.birthday_greetings', true)) {
            return 0;
        }

        $discount = Setting::where('tenant_id', $this->tenantId())
            ->where('key', 'birthday.discount_percent')
            ->value('value');

        $sent = 0;

        Member::active()
            ->whereNotNull('birth_date')
            ->whereMonth('birth_date', today()->month)
            ->whereDay('birth_date', today()->day)
            ->with('user')
            ->each(function (Member $member) use (&$sent, $discount) {
                if (! $member->user) {
                    return;
                }

                $member->user->notify(new HappyBirthday($member, $this->percent($discount)));
                $sent++;
            });

        return $sent;
    }

    /** The setting is stored as JSON, so it can arrive as a scalar or a map. */
    protected function percent(mixed $setting): ?int
    {
        $value = is_array($setting) ? ($setting['percent'] ?? null) : $setting;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function tenantId(): ?int
    {
        return app(TenantContext::class)->id();
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

        // The column is a date cast, which Laravel stores as a full
        // timestamp — comparing it against a bare "Y-m-d" matches nothing,
        // which is why these reminders were silently never sent. date() is
        // spelled the same in SQLite and MySQL.
        $byDate = Membership::active()
            ->whereNotNull('ends_at')
            ->whereIn(
                DB::raw('date(ends_at)'),
                collect($days)->map(fn (int $d) => today()->addDays($d)->toDateString())->all()
            )
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
