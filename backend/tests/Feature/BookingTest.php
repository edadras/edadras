<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Coach;
use App\Models\GymClass;
use App\Models\Member;
use App\Services\BookingService;
use RuntimeException;
use Tests\ClubTestCase;

/** Class and pool reservations, and the capacity limit behind them. */
class BookingTest extends ClubTestCase
{
    protected BookingService $bookings;

    protected GymClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookings = app(BookingService::class);

        $coach = Coach::create(['first_name' => 'Coach', 'last_name' => 'One']);

        $this->class = GymClass::create([
            'coach_id' => $coach->id,
            'name' => ['en' => 'Pilates', 'fa' => 'پیلاتس'],
            'kind' => 'class',
            'capacity' => 2,
            'duration_minutes' => 60,
            'price' => 500,
        ]);
    }

    protected function member(string $suffix): Member
    {
        return Member::create([
            'code' => "M{$suffix}",
            'first_name' => "Member{$suffix}",
            'last_name' => 'Test',
            'phone' => "0912000{$suffix}",
        ]);
    }

    protected function makeSession(?int $capacity = null): ClassSession
    {
        return ClassSession::create([
            'gym_class_id' => $this->class->id,
            'coach_id' => $this->class->coach_id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'capacity' => $capacity ?? $this->class->capacity,
            'price' => $this->class->price,
            'status' => 'scheduled',
        ]);
    }

    public function test_booking_fills_a_seat(): void
    {
        $session = $this->makeSession();

        $this->bookings->book($session, $this->member('1'));

        $this->assertSame(1, $session->fresh()->booked_count);
        $this->assertSame(1, $session->fresh()->remainingSeats());
    }

    public function test_a_full_session_stops_accepting_bookings(): void
    {
        $session = $this->makeSession(2);

        $this->bookings->book($session, $this->member('1'));
        $this->bookings->book($session, $this->member('2'));

        $this->assertTrue($session->fresh()->isFull());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session_full');

        $this->bookings->book($session->fresh(), $this->member('3'));
    }

    public function test_a_member_cannot_book_the_same_session_twice(): void
    {
        $session = $this->makeSession();
        $member = $this->member('1');

        $this->bookings->book($session, $member);

        $this->expectExceptionMessage('already_booked');

        $this->bookings->book($session->fresh(), $member);
    }

    public function test_cancelling_frees_the_seat(): void
    {
        $session = $this->makeSession(1);
        $booking = $this->bookings->book($session, $this->member('1'));

        $this->bookings->cancel($booking);

        $this->assertSame(0, $session->fresh()->booked_count);

        // And the freed seat is genuinely usable again.
        $this->bookings->book($session->fresh(), $this->member('2'));
        $this->assertSame(1, $session->fresh()->booked_count);
    }

    public function test_cancelling_twice_does_not_go_negative(): void
    {
        $session = $this->makeSession();
        $booking = $this->bookings->book($session, $this->member('1'));

        $this->bookings->cancel($booking);
        $this->bookings->cancel($booking->fresh());

        $this->assertSame(0, $session->fresh()->booked_count);
    }

    public function test_a_past_session_cannot_be_booked(): void
    {
        $session = $this->makeSession();
        $session->update(['starts_at' => now()->subHour()]);

        $this->expectExceptionMessage('session_already_started');

        $this->bookings->book($session->fresh(), $this->member('1'));
    }

    public function test_a_cancelled_session_cannot_be_booked(): void
    {
        $session = $this->makeSession();
        $session->update(['status' => 'cancelled']);

        $this->expectExceptionMessage('session_not_bookable');

        $this->bookings->book($session->fresh(), $this->member('1'));
    }

    public function test_scheduling_generates_the_weekly_timetable(): void
    {
        $created = $this->bookings->schedule(
            $this->class->id,
            [1, 3],
            '18:00',
            today()->toDateString(),
            today()->addDays(13)->toDateString(),
        );

        $this->assertSame(4, $created);
        $this->assertSame(4, ClassSession::where('gym_class_id', $this->class->id)->count());
    }

    public function test_scheduling_twice_does_not_duplicate_sessions(): void
    {
        $this->bookings->schedule($this->class->id, [1], '18:00', today()->toDateString(), today()->addDays(13)->toDateString());
        $second = $this->bookings->schedule($this->class->id, [1], '18:00', today()->toDateString(), today()->addDays(13)->toDateString());

        $this->assertSame(0, $second);
    }

    public function test_cancelling_a_session_cancels_its_bookings(): void
    {
        $session = $this->makeSession();
        $this->bookings->book($session, $this->member('1'));

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/class-sessions/{$session->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('class_bookings', ['class_session_id' => $session->id, 'status' => 'cancelled']);
    }

    public function test_the_booking_endpoint_reports_a_full_session(): void
    {
        $session = $this->makeSession(1);
        $this->bookings->book($session, $this->member('1'));

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson("/api/v1/class-sessions/{$session->id}/book", ['member_id' => $this->member('2')->id])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'session_full');
    }
}
