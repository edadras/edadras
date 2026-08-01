<?php

namespace Tests\Feature;

use App\Exceptions\CheckInException;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\CheckInService;
use App\Services\MembershipService;
use Tests\ClubTestCase;

/** The gate: quota, expiry and the double scan guard. */
class CheckInTest extends ClubTestCase
{
    protected CheckInService $checkIn;

    protected MembershipService $memberships;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkIn = app(CheckInService::class);
        $this->memberships = app(MembershipService::class);

        $this->member = Member::create([
            'code' => '1001',
            'first_name' => 'Test',
            'last_name' => 'Member',
            'phone' => '09120000000',
        ]);
    }

    public function test_a_session_pass_loses_one_session_per_entry(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('session'), ['create_invoice' => false]);
        $total = $membership->total_sessions;

        $this->checkIn->checkIn($this->member);

        $this->assertSame($total - 1, $membership->fresh()->remaining_sessions);
    }

    public function test_a_duration_pass_does_not_burn_sessions(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('duration'), ['create_invoice' => false]);

        $attendance = $this->checkIn->checkIn($this->member);

        $this->assertNull($membership->fresh()->remaining_sessions);
        $this->assertFalse($attendance->consumed_session);
    }

    public function test_the_last_session_expires_the_membership(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => 'Single'],
            'type' => 'session',
            'session_count' => 1,
            'duration_days' => 30,
            'price' => 100,
        ]);

        $membership = $this->memberships->sell($this->member, $plan, ['create_invoice' => false]);

        $this->checkIn->checkIn($this->member);
        $this->checkIn->checkOut($this->member);

        $this->assertSame(Membership::EXPIRED, $membership->fresh()->status);

        $this->expectException(CheckInException::class);
        $this->checkIn->checkIn($this->member);
    }

    public function test_time_runs_out_even_when_sessions_remain(): void
    {
        $plan = MembershipPlan::create([
            'name' => ['en' => '30 sessions in 30 days'],
            'type' => 'session',
            'session_count' => 30,
            'duration_days' => 30,
            'price' => 100,
        ]);

        $membership = $this->memberships->sell($this->member, $plan, [
            'starts_at' => today()->subDays(40),
            'create_invoice' => false,
        ]);

        $this->assertSame(30, $membership->remaining_sessions);
        $this->assertTrue($membership->isExpiredByDate());

        try {
            $this->checkIn->checkIn($this->member);
            $this->fail('An expired membership must not open the gate.');
        } catch (CheckInException $e) {
            $this->assertSame('expired', $e->reason);
        }

        $this->assertSame(Membership::EXPIRED, $membership->fresh()->status);
    }

    public function test_a_membership_that_has_not_started_is_refused(): void
    {
        $this->memberships->sell($this->member, $this->plan('duration'), [
            'starts_at' => today()->addWeek(),
            'create_invoice' => false,
        ]);

        try {
            $this->checkIn->checkIn($this->member);
            $this->fail('A future membership must not open the gate.');
        } catch (CheckInException $e) {
            $this->assertSame('not_started', $e->reason);
        }
    }

    public function test_a_member_without_a_membership_is_refused(): void
    {
        try {
            $this->checkIn->checkIn($this->member);
            $this->fail('A member with no pass must not open the gate.');
        } catch (CheckInException $e) {
            $this->assertSame('no_membership', $e->reason);
        }
    }

    public function test_a_second_scan_without_a_check_out_is_refused(): void
    {
        $this->memberships->sell($this->member, $this->plan('session'), ['create_invoice' => false]);

        $this->checkIn->checkIn($this->member);

        try {
            $this->checkIn->checkIn($this->member);
            $this->fail('Two entries in a row must be refused.');
        } catch (CheckInException $e) {
            $this->assertSame('already_inside', $e->reason);
        }
    }

    public function test_a_double_scan_does_not_double_charge_the_quota(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('session'), ['create_invoice' => false]);
        $total = $membership->total_sessions;

        $this->checkIn->checkIn($this->member);

        try {
            $this->checkIn->checkIn($this->member);
        } catch (CheckInException) {
            // expected
        }

        $this->assertSame($total - 1, $membership->fresh()->remaining_sessions);
    }

    public function test_check_out_records_the_duration(): void
    {
        $this->memberships->sell($this->member, $this->plan('duration'), ['create_invoice' => false]);

        $attendance = $this->checkIn->checkIn($this->member);
        $attendance->update(['checked_in_at' => now()->subMinutes(75)]);

        $closed = $this->checkIn->checkOut($this->member);

        $this->assertNotNull($closed->checked_out_at);
        $this->assertEqualsWithDelta(75, $closed->durationMinutes(), 1);
    }

    public function test_the_preview_reports_why_entry_would_be_refused(): void
    {
        $preview = $this->checkIn->preview($this->member);

        $this->assertFalse($preview['allowed']);
        $this->assertSame('no_membership', $preview['reason']);
    }

    public function test_the_preview_does_not_touch_the_quota(): void
    {
        $membership = $this->memberships->sell($this->member, $this->plan('session'), ['create_invoice' => false]);
        $before = $membership->remaining_sessions;

        $preview = $this->checkIn->preview($this->member);

        $this->assertTrue($preview['allowed']);
        $this->assertSame($before, $membership->fresh()->remaining_sessions);
    }

    public function test_a_blocked_member_cannot_enter(): void
    {
        $this->memberships->sell($this->member, $this->plan('duration'), ['create_invoice' => false]);
        $this->member->update(['status' => 'blocked']);

        try {
            $this->checkIn->checkIn($this->member->fresh());
            $this->fail('A blocked member must not open the gate.');
        } catch (CheckInException $e) {
            $this->assertSame('member_blocked', $e->reason);
        }
    }

    public function test_an_unknown_qr_code_is_rejected(): void
    {
        $this->expectException(CheckInException::class);

        $this->checkIn->findByQrToken('not-a-real-token');
    }

    public function test_the_scanner_endpoint_records_an_entry(): void
    {
        $this->memberships->sell($this->member, $this->plan('session'), ['create_invoice' => false]);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/attendance/check-in', [
                'qr_token' => $this->member->qr_token,
                'method' => 'qr',
                'device' => 'front-desk-1',
            ]);

        $response->assertCreated()->assertJsonPath('attendance.method', 'qr');
        $this->assertDatabaseCount('attendances', 1);
    }

    public function test_the_scanner_endpoint_returns_a_reason_when_it_refuses(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/attendance/check-in', ['qr_token' => $this->member->qr_token]);

        $response->assertStatus(422)->assertJsonPath('reason', 'no_membership');
    }

    public function test_an_nfc_wristband_opens_the_gate_too(): void
    {
        $this->memberships->sell($this->member, $this->plan('duration'), ['create_invoice' => false]);
        $this->member->update(['nfc_uid' => '04A2B3C4D5']);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/attendance/check-in', ['nfc_uid' => '04A2B3C4D5', 'method' => 'nfc'])
            ->assertCreated();

        $this->assertDatabaseHas('attendances', ['method' => 'nfc']);
    }
}
