<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Coach;
use App\Models\GymClass;
use App\Models\Member;
use App\Models\WorkoutPlan;
use App\Services\BookingService;
use Tests\ClubTestCase;

/** A coach's own list of members, which the manager app needs. */
class CoachStudentsTest extends ClubTestCase
{
    protected Coach $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = Coach::create(['first_name' => 'Reza', 'last_name' => 'Coach']);
    }

    protected function member(string $code): Member
    {
        return Member::create([
            'code' => $code,
            'first_name' => 'M'.$code,
            'last_name' => 'Test',
            'phone' => '0912000'.$code,
        ]);
    }

    protected function makeSession(?Coach $coach = null): ClassSession
    {
        $class = GymClass::create([
            'coach_id' => ($coach ?? $this->coach)->id,
            'name' => ['en' => 'Yoga'],
            'capacity' => 10,
            'duration_minutes' => 60,
            'price' => 100,
        ]);

        return ClassSession::create([
            'gym_class_id' => $class->id,
            'coach_id' => ($coach ?? $this->coach)->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'capacity' => 10,
            'status' => 'scheduled',
        ]);
    }

    public function test_members_with_an_active_program_from_this_coach_are_listed(): void
    {
        $member = $this->member('8001');

        WorkoutPlan::create([
            'member_id' => $member->id,
            'coach_id' => $this->coach->id,
            'title' => 'Push pull legs',
            'status' => 'active',
        ]);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/coaches/{$this->coach->id}/students")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('8001', $response->json('students.0.code'));
        $this->assertSame('program', $response->json('students.0.source'));
    }

    public function test_members_booked_onto_this_coach_sessions_are_listed(): void
    {
        $member = $this->member('8002');

        app(BookingService::class)->book($this->makeSession(), $member);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/coaches/{$this->coach->id}/students")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('class', $response->json('students.0.source'));
    }

    public function test_someone_on_both_counts_once_and_says_so(): void
    {
        $member = $this->member('8003');

        WorkoutPlan::create([
            'member_id' => $member->id,
            'coach_id' => $this->coach->id,
            'title' => 'Plan',
            'status' => 'active',
        ]);
        app(BookingService::class)->book($this->makeSession(), $member);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/coaches/{$this->coach->id}/students")
            ->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('both', $response->json('students.0.source'));
    }

    public function test_another_coach_members_are_not_listed(): void
    {
        $other = Coach::create(['first_name' => 'Sara', 'last_name' => 'Other']);
        $member = $this->member('8004');

        WorkoutPlan::create([
            'member_id' => $member->id,
            'coach_id' => $other->id,
            'title' => 'Plan',
            'status' => 'active',
        ]);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/coaches/{$this->coach->id}/students")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_a_finished_program_no_longer_counts(): void
    {
        $member = $this->member('8005');

        WorkoutPlan::create([
            'member_id' => $member->id,
            'coach_id' => $this->coach->id,
            'title' => 'Old plan',
            'status' => 'completed',
        ]);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/coaches/{$this->coach->id}/students")
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_it_needs_the_coaches_permission(): void
    {
        $this->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/coaches/{$this->coach->id}/students")
            ->assertUnauthorized();
    }
}
