<?php

namespace App\Exceptions;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\Membership;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Every refusal at the gate carries a machine readable reason so the
 * scanner app can show the right message in the right language.
 */
class CheckInException extends Exception
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function unknownCode(): self
    {
        return new self('unknown_code', 'No member matches this code.', [], 404);
    }

    public static function memberBlocked(Member $member): self
    {
        return new self('member_blocked', 'This member is not active.', ['member_id' => $member->id]);
    }

    public static function noMembership(Member $member): self
    {
        return new self('no_membership', 'This member has no active membership.', ['member_id' => $member->id]);
    }

    public static function notStarted(Membership $membership): self
    {
        return new self('not_started', 'This membership has not started yet.', [
            'starts_at' => $membership->starts_at->toDateString(),
        ]);
    }

    public static function expired(Membership $membership): self
    {
        return new self('expired', 'This membership has expired.', [
            'ends_at' => $membership->ends_at?->toDateString(),
        ]);
    }

    public static function noSessionsLeft(Membership $membership): self
    {
        return new self('no_sessions_left', 'No sessions left on this membership.', [
            'membership_id' => $membership->id,
        ]);
    }

    public static function alreadyInside(Attendance $attendance): self
    {
        return new self('already_inside', 'This member is already checked in.', [
            'checked_in_at' => $attendance->checked_in_at->toIso8601String(),
        ]);
    }

    public static function notInside(Member $member): self
    {
        return new self('not_inside', 'This member has no open check-in.', ['member_id' => $member->id]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('checkin.'.$this->reason),
            'reason' => $this->reason,
            'context' => $this->context,
        ], $this->status);
    }
}
