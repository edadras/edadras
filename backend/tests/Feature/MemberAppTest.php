<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassSession;
use App\Models\Coach;
use App\Models\Conversation;
use App\Models\GymClass;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MembershipExpiring;
use App\Services\BookingService;
use App\Services\CheckInService;
use App\Services\MembershipService;
use Tests\ClubTestCase;

/** The member's own app: their card, their bookings, nobody else's. */
class MemberAppTest extends ClubTestCase
{
    protected Member $member;

    protected User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberUser = User::create([
            'tenant_id' => $this->club->id,
            'name' => 'Sara Member',
            'email' => 'sara@test.club',
            'phone' => '09121234567',
            'password' => 'password',
        ]);

        $this->memberUser->roles()->attach(
            Role::where('tenant_id', $this->club->id)->where('slug', 'member')->value('id')
        );

        $this->member = Member::create([
            'user_id' => $this->memberUser->id,
            'code' => '1001',
            'first_name' => 'Sara',
            'last_name' => 'Member',
            'phone' => '09121234567',
        ]);

        app(MembershipService::class)->sell($this->member, $this->plan('session'), ['create_invoice' => false]);
    }

    protected function asMember(): static
    {
        $this->actingAs($this->memberUser->fresh('roles'), 'sanctum');

        return $this;
    }

    public function test_the_dashboard_shows_the_pass_at_a_glance(): void
    {
        $response = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/me/dashboard');

        $response->assertOk()
            ->assertJsonPath('member.code', '1001')
            ->assertJsonPath('club.name', 'Test Club')
            ->assertJsonStructure(['remaining_sessions', 'days_remaining', 'qr_token', 'wallet_balance']);

        $this->assertSame($this->member->qr_token, $response->json('qr_token'));
    }

    public function test_a_member_can_book_and_cancel_a_class(): void
    {
        $coach = Coach::create(['first_name' => 'C', 'last_name' => 'One']);
        $class = GymClass::create([
            'coach_id' => $coach->id,
            'name' => ['en' => 'Yoga'],
            'capacity' => 10,
            'duration_minutes' => 60,
            'price' => 100,
        ]);

        $session = ClassSession::create([
            'gym_class_id' => $class->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'capacity' => 10,
            'price' => 100,
            'status' => 'scheduled',
        ]);

        $booking = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/me/sessions/{$session->id}/book")
            ->assertCreated()
            ->json();

        $this->assertSame(1, $session->fresh()->booked_count);

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/me/bookings/{$booking['id']}/cancel")
            ->assertOk();

        $this->assertSame(0, $session->fresh()->booked_count);
    }

    public function test_a_member_cannot_cancel_someone_else_booking(): void
    {
        $other = Member::create(['code' => '1002', 'first_name' => 'Other', 'last_name' => 'Member', 'phone' => '09129999999']);

        $coach = Coach::create(['first_name' => 'C', 'last_name' => 'Two']);
        $class = GymClass::create(['coach_id' => $coach->id, 'name' => ['en' => 'TRX'], 'capacity' => 5, 'duration_minutes' => 45, 'price' => 100]);
        $session = ClassSession::create([
            'gym_class_id' => $class->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'capacity' => 5,
            'status' => 'scheduled',
        ]);

        $booking = app(BookingService::class)->book($session, $other);

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/me/bookings/{$booking->id}/cancel")
            ->assertForbidden();
    }

    public function test_the_schedule_marks_what_is_already_booked(): void
    {
        $coach = Coach::create(['first_name' => 'C', 'last_name' => 'Three']);
        $class = GymClass::create(['coach_id' => $coach->id, 'name' => ['en' => 'Pilates'], 'capacity' => 5, 'duration_minutes' => 60, 'price' => 100]);
        $session = ClassSession::create([
            'gym_class_id' => $class->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'capacity' => 5,
            'status' => 'scheduled',
        ]);

        app(BookingService::class)->book($session, $this->member);

        $response = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/me/schedule');

        $response->assertOk()->assertJsonPath('0.is_booked', true);
    }

    public function test_a_member_only_sees_their_own_history(): void
    {
        $other = Member::create(['code' => '1002', 'first_name' => 'Other', 'last_name' => 'Member', 'phone' => '09129999999']);
        $otherMembership = app(MembershipService::class)->sell($other, $this->plan('duration'), ['create_invoice' => false]);

        Attendance::create([
            'member_id' => $other->id,
            'membership_id' => $otherMembership->id,
            'checked_in_at' => now()->subDay(),
        ]);

        app(CheckInService::class)->checkIn($this->member);

        $response = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/me/attendance');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($this->member->id, $response->json('data.0.member_id'));
    }

    public function test_a_member_can_update_their_own_profile(): void
    {
        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->putJson('/api/v1/me/profile', [
                'weight' => 71.5,
                'blood_type' => 'O+',
                'emergency_phone' => '09120001111',
            ])
            ->assertOk();

        $this->assertEquals(71.5, (float) $this->member->fresh()->weight);
    }

    public function test_a_staff_account_without_a_member_profile_is_refused(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/me/dashboard')
            ->assertForbidden();
    }

    public function test_member_login_issues_a_token(): void
    {
        $this->postJson('/api/v1/auth/member-login', [
            'phone' => '09121234567',
            'password' => 'password',
        ], $this->clubHeaders())
            ->assertOk()
            ->assertJsonStructure(['token', 'member', 'club']);
    }

    public function test_a_member_reads_and_clears_their_notifications(): void
    {
        $membership = $this->member->activeMembership;
        $this->memberUser->notify(new MembershipExpiring($membership));

        $response = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/me/notifications');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.type', 'membership_expiring')
            ->assertJsonPath('data.0.read_at', null);

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/me/notifications/read')
            ->assertOk();

        $this->assertSame(0, $this->memberUser->fresh()->unreadNotifications()->count());
    }

    public function test_a_member_never_sees_another_member_notifications(): void
    {
        $otherUser = User::create([
            'tenant_id' => $this->club->id,
            'name' => 'Other Member',
            'email' => 'other@test.club',
            'phone' => '09129999999',
            'password' => 'password',
        ]);
        Member::create(['user_id' => $otherUser->id, 'code' => '1002', 'first_name' => 'Other', 'last_name' => 'Member', 'phone' => '09129999999']);

        $otherUser->notify(new MembershipExpiring($this->member->activeMembership));

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_member_only_sees_their_own_chat_threads(): void
    {
        $other = Member::create(['code' => '1002', 'first_name' => 'Other', 'last_name' => 'Member', 'phone' => '09129999999']);
        $coach = Coach::create(['first_name' => 'C', 'last_name' => 'Four']);

        $mine = Conversation::create(['member_id' => $this->member->id, 'coach_id' => $coach->id, 'last_message_at' => now()]);
        $theirs = Conversation::create(['member_id' => $other->id, 'coach_id' => $coach->id, 'last_message_at' => now()]);

        $theirs->messages()->create([
            'tenant_id' => $this->club->id,
            'sender_type' => 'coach',
            'sender_id' => $this->memberUser->id,
            'body' => 'Private note for the other member.',
        ]);

        $response = $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/conversations');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($mine->id, $response->json('data.0.id'));

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/conversations/{$theirs->id}/messages")
            ->assertForbidden();

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/conversations/{$theirs->id}/messages", ['body' => 'Hello?'])
            ->assertForbidden();
    }

    public function test_a_member_can_write_in_their_own_thread(): void
    {
        $coach = Coach::create(['first_name' => 'C', 'last_name' => 'Five']);
        $conversation = Conversation::create(['member_id' => $this->member->id, 'coach_id' => $coach->id, 'last_message_at' => now()]);

        $this->asMember()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/conversations/{$conversation->id}/messages", ['body' => 'When is my next session?'])
            ->assertCreated()
            ->assertJsonPath('sender_type', 'member');

        $this->assertSame(1, $conversation->messages()->count());
    }

    public function test_member_login_needs_the_right_club(): void
    {
        $this->postJson('/api/v1/auth/member-login', [
            'phone' => '09121234567',
            'password' => 'wrong-password',
        ], $this->clubHeaders())
            ->assertStatus(422);
    }
}
