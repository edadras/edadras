<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\Ai\AiInsightService;
use App\Services\Ai\AiProgramGenerator;
use App\Services\MembershipService;
use Tests\ClubTestCase;

/**
 * The AI module runs on the club's own data. With no API key configured the
 * statistical engine and the program templates must still work.
 */
class AiModuleTest extends ClubTestCase
{
    protected AiInsightService $insights;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gymflow.ai.api_key' => null]);

        $this->insights = app(AiInsightService::class);
    }

    protected function memberWithVisits(string $code, int $visits, int $daysSinceLast): Member
    {
        $member = Member::create([
            'code' => $code,
            'first_name' => "M{$code}",
            'last_name' => 'Test',
            'phone' => "0912{$code}",
        ]);

        $membership = app(MembershipService::class)->sell($member, $this->plan('duration'), ['create_invoice' => false]);

        for ($i = 0; $i < $visits; $i++) {
            Attendance::create([
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'checked_in_at' => now()->subDays($daysSinceLast + $i * 2),
                'checked_out_at' => now()->subDays($daysSinceLast + $i * 2)->addHour(),
            ]);
        }

        return $member;
    }

    public function test_an_absent_member_scores_higher_than_a_regular_one(): void
    {
        $regular = $this->memberWithVisits('1001', 10, 1);
        $absent = $this->memberWithVisits('1002', 10, 25);

        $scores = $this->insights->churnRisk()->keyBy(fn ($risk) => $risk['member']->code);

        $this->assertGreaterThan($scores['1001']['score'], $scores['1002']['score']);
    }

    public function test_the_risk_score_stays_inside_its_range(): void
    {
        $this->memberWithVisits('1001', 1, 89);

        $risk = $this->insights->churnRisk()->first();

        $this->assertGreaterThanOrEqual(0, $risk['score']);
        $this->assertLessThanOrEqual(100, $risk['score']);
    }

    public function test_a_long_absence_is_flagged_critical_with_a_reason(): void
    {
        $this->memberWithVisits('1001', 2, 60);

        $risk = $this->insights->churnRisk()->first();

        $this->assertSame('critical', $risk['severity']);
        $this->assertNotEmpty($risk['reasons']);
    }

    public function test_a_member_without_a_membership_carries_risk(): void
    {
        Member::create(['code' => '2001', 'first_name' => 'No', 'last_name' => 'Pass', 'phone' => '09122001']);

        $risk = $this->insights->churnRisk()->first();

        $this->assertNull($risk['days_remaining']);
        $this->assertGreaterThan(0, $risk['score']);
    }

    public function test_the_forecast_projects_the_month_from_the_pace_so_far(): void
    {
        Transaction::create([
            'type' => Transaction::INCOME,
            'category' => 'membership',
            'amount' => 1_000_000,
            'occurred_at' => today()->startOfMonth(),
        ]);

        $forecast = $this->insights->revenueForecast();

        $this->assertEquals(1_000_000, $forecast['earned_so_far']);
        $this->assertGreaterThanOrEqual($forecast['earned_so_far'], $forecast['projected_total']);
    }

    public function test_the_attendance_analysis_finds_the_peak_hour(): void
    {
        $member = $this->memberWithVisits('1001', 1, 1);
        $membership = $member->activeMembership;

        foreach (range(1, 5) as $i) {
            Attendance::create([
                'member_id' => $member->id,
                'membership_id' => $membership->id,
                'checked_in_at' => now()->subDays($i)->setTime(19, 0),
            ]);
        }

        $analysis = $this->insights->attendanceAnalysis();

        $this->assertSame('19', $analysis['peak_hour']);
        $this->assertCount(24, $analysis['by_hour']);
    }

    public function test_campaign_suggestions_appear_when_members_go_quiet(): void
    {
        $this->memberWithVisits('1001', 3, 60);

        $keys = collect($this->insights->campaignSuggestions())->pluck('key');

        $this->assertTrue($keys->contains('win_back'));
    }

    public function test_refreshing_stores_the_insights(): void
    {
        $this->memberWithVisits('1001', 2, 45);

        $stored = $this->insights->refresh();

        $this->assertGreaterThan(0, $stored);
        $this->assertTrue(AiInsight::where('type', 'revenue_forecast')->exists());
        $this->assertTrue(AiInsight::where('type', 'churn_risk')->exists());
    }

    public function test_refreshing_twice_updates_rather_than_duplicates(): void
    {
        $this->memberWithVisits('1001', 2, 45);

        $this->insights->refresh();
        $this->insights->refresh();

        $this->assertSame(1, AiInsight::where('type', 'revenue_forecast')->count());
    }

    public function test_a_workout_program_is_produced_without_an_api_key(): void
    {
        $member = $this->memberWithVisits('1001', 1, 1);

        $plan = app(AiProgramGenerator::class)->workoutPlan($member, [
            'goal' => 'muscle_gain',
            'days_per_week' => 4,
            'level' => 'beginner',
        ]);

        $this->assertTrue($plan->generated_by_ai);
        $this->assertCount(4, $plan->days);
        $this->assertGreaterThan(0, $plan->days->first()->exercises->count());
    }

    public function test_a_meal_plan_hits_roughly_the_calorie_target(): void
    {
        $member = $this->memberWithVisits('1001', 1, 1);
        $member->update(['weight' => 80, 'height' => 180, 'birth_date' => now()->subYears(30), 'gender' => 'male']);

        $plan = app(AiProgramGenerator::class)->nutritionPlan($member->fresh(), ['goal' => 'fat_loss']);

        $mealTotal = $plan->meals->sum('calories');

        $this->assertNotEmpty($plan->meals);
        $this->assertEqualsWithDelta($plan->daily_calories, $mealTotal, $plan->daily_calories * 0.05);
    }

    public function test_the_calorie_estimate_follows_the_goal(): void
    {
        $member = $this->memberWithVisits('1001', 1, 1);
        $member->update(['weight' => 80, 'height' => 180, 'birth_date' => now()->subYears(30), 'gender' => 'male']);

        $generator = app(AiProgramGenerator::class);
        $member = $member->fresh();

        $this->assertLessThan(
            $generator->estimateCalories($member, 'muscle_gain'),
            $generator->estimateCalories($member, 'fat_loss'),
        );
    }

    public function test_the_assistant_reports_itself_unavailable_without_a_key(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/ai/chat', ['question' => 'How are we doing this month?']);

        $response->assertOk()->assertJsonPath('available', false);
        $this->assertArrayHasKey('revenue', $response->json('context'));
    }

    public function test_the_overview_endpoint_answers_with_the_full_picture(): void
    {
        $this->memberWithVisits('1001', 4, 20);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/ai/overview')
            ->assertOk()
            ->assertJsonStructure([
                'assistant_available',
                'forecast' => ['projected_total'],
                'attendance' => ['peak_hour'],
                'churn_risk',
                'campaign_suggestions',
            ]);
    }

    public function test_the_snapshot_never_carries_another_club_numbers(): void
    {
        $this->memberWithVisits('1001', 3, 5);

        $snapshot = $this->insights->snapshot();

        $this->assertSame(1, $snapshot['members']['total']);
    }
}
