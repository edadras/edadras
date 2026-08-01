<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sells, renews, freezes and expires memberships. The duration and the
 * session quota are both enforced: whichever runs out first ends the pass.
 */
class MembershipService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function sell(Member $member, MembershipPlan $plan, array $attributes = []): Membership
    {
        return DB::transaction(function () use ($member, $plan, $attributes) {
            $startsAt = CarbonImmutable::parse($attributes['starts_at'] ?? today());

            $membership = Membership::create([
                'member_id' => $member->id,
                'membership_plan_id' => $plan->id,
                'type' => $plan->type,
                'starts_at' => $startsAt,
                'ends_at' => $this->resolveEndDate($plan, $startsAt),
                'total_sessions' => $plan->session_count,
                'remaining_sessions' => $plan->session_count,
                'price' => $attributes['price'] ?? $plan->price,
                'discount' => $attributes['discount'] ?? 0,
                'status' => Membership::ACTIVE,
                'created_by' => $attributes['created_by'] ?? auth()->id(),
                'notes' => $attributes['notes'] ?? null,
            ]);

            if ($attributes['create_invoice'] ?? true) {
                $this->invoices->forMembership($membership);
            }

            return $membership->fresh();
        });
    }

    /**
     * Renewing continues from the current end date when the pass is still
     * running, otherwise it starts today.
     */
    public function renew(Membership $membership, ?MembershipPlan $plan = null, array $attributes = []): Membership
    {
        $plan ??= $membership->plan;

        $startsAt = $membership->ends_at && $membership->ends_at->isFuture()
            ? CarbonImmutable::parse($membership->ends_at)->addDay()
            : CarbonImmutable::parse(today());

        return $this->sell($membership->member, $plan, array_merge($attributes, [
            'starts_at' => $startsAt,
        ]));
    }

    public function freeze(Membership $membership, CarbonImmutable $from, CarbonImmutable $until): Membership
    {
        $days = (int) $from->diffInDays($until);

        $membership->update([
            'status' => Membership::FROZEN,
            'frozen_from' => $from,
            'frozen_until' => $until,
            // The paid days are pushed forward, freezing never costs the member.
            'ends_at' => $membership->ends_at?->addDays($days),
        ]);

        return $membership;
    }

    public function unfreeze(Membership $membership): Membership
    {
        $membership->update([
            'status' => Membership::ACTIVE,
            'frozen_from' => null,
            'frozen_until' => null,
        ]);

        return $membership;
    }

    public function cancel(Membership $membership, ?string $reason = null): Membership
    {
        $membership->update([
            'status' => Membership::CANCELLED,
            'notes' => trim($membership->notes."\n".$reason),
        ]);

        return $membership;
    }

    /** Nightly sweep, also run before the dashboard counts anything. */
    public function expireOverdue(): int
    {
        return Membership::query()
            ->where('status', Membership::ACTIVE)
            ->where(function ($query) {
                $query->whereDate('ends_at', '<', today())
                    ->orWhere('remaining_sessions', '<=', 0);
            })
            ->update(['status' => Membership::EXPIRED]);
    }

    protected function resolveEndDate(MembershipPlan $plan, CarbonImmutable $startsAt): ?CarbonImmutable
    {
        // A session pass may also carry a deadline: 30 sessions within 30
        // days expires on the date even if sessions are left.
        return $plan->duration_days
            ? $startsAt->addDays($plan->duration_days - 1)
            : null;
    }
}
