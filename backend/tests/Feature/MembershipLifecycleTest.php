<?php

namespace Tests\Feature;

use App\Exceptions\CheckInException;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\CheckInService;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Tests\ClubTestCase;

/** Selling, renewing, freezing and expiring a membership. */
class MembershipLifecycleTest extends ClubTestCase
{
    protected MembershipService $memberships;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberships = app(MembershipService::class);

        $this->member = Member::create([
            'code' => '1001',
            'first_name' => 'Test',
            'last_name' => 'Member',
            'phone' => '09120000000',
        ]);
    }

    public function test_a_new_club_starts_with_a_price_list(): void
    {
        $this->assertGreaterThanOrEqual(6, MembershipPlan::count());
        $this->assertTrue(MembershipPlan::where('type', 'session')->exists());
    }

    public function test_a_duration_plan_sets_the_end_date(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => '30 days'],
            'type' => 'duration',
            'duration_days' => 30,
            'price' => 100,
        ]);

        $membership = $this->memberships->sell($this->member, $plan, [
            'starts_at' => '2026-03-01',
            'create_invoice' => false,
        ]);

        $this->assertSame('2026-03-30', $membership->ends_at->toDateString());
    }

    public function test_renewing_continues_from_the_current_end_date(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => '30 days'],
            'type' => 'duration',
            'duration_days' => 30,
            'price' => 100,
        ]);

        $first = $this->memberships->sell($this->member, $plan, [
            'starts_at' => today()->toDateString(),
            'create_invoice' => false,
        ]);

        $second = $this->memberships->renew($first, null, ['create_invoice' => false]);

        $this->assertTrue($second->starts_at->isSameDay($first->ends_at->copy()->addDay()));
    }

    public function test_renewing_an_expired_pass_starts_today(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => '30 days'],
            'type' => 'duration',
            'duration_days' => 30,
            'price' => 100,
        ]);

        $expired = $this->memberships->sell($this->member, $plan, [
            'starts_at' => today()->subDays(60)->toDateString(),
            'create_invoice' => false,
        ]);

        $renewed = $this->memberships->renew($expired, null, ['create_invoice' => false]);

        $this->assertTrue($renewed->starts_at->isToday());
    }

    public function test_freezing_pushes_the_end_date_out(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => '30 days'],
            'type' => 'duration',
            'duration_days' => 30,
            'price' => 100,
        ]);

        $membership = $this->memberships->sell($this->member, $plan, [
            'starts_at' => today()->toDateString(),
            'create_invoice' => false,
        ]);

        $originalEnd = $membership->ends_at->copy();

        $this->memberships->freeze(
            $membership,
            CarbonImmutable::parse(today()),
            CarbonImmutable::parse(today()->addDays(10)),
        );

        $membership->refresh();

        $this->assertSame(Membership::FROZEN, $membership->status);
        $this->assertSame($originalEnd->addDays(10)->toDateString(), $membership->ends_at->toDateString());
    }

    public function test_a_frozen_pass_cannot_open_the_gate(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('duration'), ['create_invoice' => false]);

        $this->memberships->freeze(
            $membership,
            CarbonImmutable::parse(today()),
            CarbonImmutable::parse(today()->addDays(7)),
        );

        $this->expectException(CheckInException::class);

        app(CheckInService::class)->checkIn($this->member);
    }

    public function test_unfreezing_puts_the_pass_back(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('duration'), ['create_invoice' => false]);

        $this->memberships->freeze($membership, CarbonImmutable::parse(today()), CarbonImmutable::parse(today()->addDays(5)));
        $this->memberships->unfreeze($membership->fresh());

        $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
        $this->assertNull($membership->fresh()->frozen_until);
    }

    public function test_the_nightly_sweep_expires_what_ran_out(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => 'short'],
            'type' => 'duration',
            'duration_days' => 5,
            'price' => 100,
        ]);

        $this->memberships->sell($this->member, $plan, [
            'starts_at' => today()->subDays(30)->toDateString(),
            'create_invoice' => false,
        ]);

        $expired = $this->memberships->expireOverdue();

        $this->assertSame(1, $expired);
        $this->assertSame(0, Membership::active()->count());
    }

    public function test_expiring_soon_finds_the_right_window(): void
    {
        $plan = MembershipPlan::create(['name' => ['en' => 'p'], 'type' => 'duration', 'duration_days' => 30, 'price' => 1]);

        $this->memberships->sell($this->member, $plan, [
            'starts_at' => today()->subDays(27)->toDateString(),
            'create_invoice' => false,
        ]);

        $other = Member::create(['code' => '2', 'first_name' => 'B', 'last_name' => 'C', 'phone' => '09120000002']);
        $this->memberships->sell($other, $plan, ['starts_at' => today()->toDateString(), 'create_invoice' => false]);

        $this->assertSame(1, Membership::expiringWithin(7)->count());
    }

    public function test_the_sell_endpoint_creates_a_membership_and_an_invoice(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/memberships', [
                'member_id' => $this->member->id,
                'membership_plan_id' => $this->plan('session')->id,
            ]);

        $response->assertCreated();
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_sessions_can_be_adjusted_by_hand(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('session'), ['create_invoice' => false]);
        $before = $membership->remaining_sessions;

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/memberships/{$membership->id}/sessions", ['sessions' => 5, 'reason' => 'make good'])
            ->assertOk();

        $this->assertSame($before + 5, $membership->fresh()->remaining_sessions);
    }
}
