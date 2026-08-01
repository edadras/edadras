<?php

namespace App\Services;

use App\Exceptions\CheckInException;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Membership;
use Illuminate\Support\Facades\DB;

/**
 * The gate. Validates a QR / NFC scan against the member's membership,
 * burns one session from the quota and writes the attendance row.
 */
class CheckInService
{
    /**
     * @param  string  $method  qr, nfc or manual
     */
    public function checkIn(Member $member, ?int $staffId = null, string $method = 'qr', ?string $device = null): Attendance
    {
        // Validation runs before the transaction opens: refusing entry often
        // means marking a pass expired, and that write has to survive the
        // exception that the refusal itself throws.
        $this->guard($member);

        return DB::transaction(function () use ($member, $staffId, $method, $device) {
            $membership = $this->resolveMembership($member);
            $consumed = false;

            // A session based pass loses one session per entry; a duration
            // pass only needs to be inside its date window.
            if ($membership->remaining_sessions !== null) {
                $membership->decrement('remaining_sessions');
                $membership->refresh();
                $consumed = true;

                if ($membership->remaining_sessions <= 0) {
                    $membership->update(['status' => Membership::EXPIRED]);
                }
            }

            return Attendance::create([
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'checked_in_at' => now(),
                'method' => $method,
                'device' => $device,
                'staff_id' => $staffId,
                'consumed_session' => $consumed,
            ]);
        });
    }

    public function checkOut(Member $member, ?int $staffId = null): Attendance
    {
        $attendance = Attendance::where('member_id', $member->id)
            ->stillInside()
            ->latest('checked_in_at')
            ->first();

        if (! $attendance) {
            throw CheckInException::notInside($member);
        }

        $attendance->update([
            'checked_out_at' => now(),
            'staff_id' => $attendance->staff_id ?? $staffId,
        ]);

        return $attendance;
    }

    public function findByQrToken(string $token): Member
    {
        $member = Member::where('qr_token', $token)->first();

        if (! $member) {
            throw CheckInException::unknownCode();
        }

        return $member;
    }

    public function findByNfcUid(string $uid): Member
    {
        $member = Member::where('nfc_uid', $uid)->first();

        if (! $member) {
            throw CheckInException::unknownCode();
        }

        return $member;
    }

    /**
     * A dry run used by the scanner screen to show a green / red card before
     * the receptionist confirms the entry.
     */
    public function preview(Member $member): array
    {
        $membership = $member->memberships()
            ->where('status', Membership::ACTIVE)
            ->orderBy('ends_at')
            ->first();

        $reason = match (true) {
            $member->status !== 'active' => 'member_blocked',
            $membership === null => 'no_membership',
            $membership->starts_at->isAfter(today()) => 'not_started',
            $membership->isExpiredByDate() => 'expired',
            $membership->isOutOfSessions() => 'no_sessions_left',
            default => null,
        };

        return [
            'allowed' => $reason === null,
            'reason' => $reason,
            'member' => $member,
            'membership' => $membership,
            'remaining_sessions' => $membership?->remaining_sessions,
            'days_remaining' => $membership?->daysRemaining(),
        ];
    }

    /**
     * Everything that can refuse an entry, checked and committed before the
     * write transaction starts.
     */
    protected function guard(Member $member): void
    {
        if ($member->status !== 'active') {
            throw CheckInException::memberBlocked($member);
        }

        $open = Attendance::where('member_id', $member->id)->stillInside()->first();

        if ($open) {
            throw CheckInException::alreadyInside($open);
        }

        $membership = $this->currentMembership($member);

        if (! $membership) {
            throw CheckInException::noMembership($member);
        }

        if ($membership->starts_at->isAfter(today())) {
            throw CheckInException::notStarted($membership);
        }

        // A pass that ran out of days or sessions is retired on the spot, so
        // the dashboard and the next scan agree with each other.
        if ($membership->isExpiredByDate()) {
            $membership->update(['status' => Membership::EXPIRED]);

            throw CheckInException::expired($membership);
        }

        if ($membership->isOutOfSessions()) {
            $membership->update(['status' => Membership::EXPIRED]);

            throw CheckInException::noSessionsLeft($membership);
        }
    }

    /**
     * Re-reads the pass under a row lock so two scanners cannot spend the
     * same session twice. The guard above has already ruled out the states
     * that need a write, so anything left here is a genuine race.
     */
    protected function resolveMembership(Member $member): Membership
    {
        $membership = $this->currentMembership($member, lock: true);

        if (! $membership || $membership->isExpiredByDate() || $membership->isOutOfSessions()) {
            throw CheckInException::noMembership($member);
        }

        return $membership;
    }

    /** The pass closest to expiry, so quotas never go stale. */
    protected function currentMembership(Member $member, bool $lock = false): ?Membership
    {
        return $member->memberships()
            ->where('status', Membership::ACTIVE)
            ->orderByRaw('ends_at is null, ends_at asc')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
