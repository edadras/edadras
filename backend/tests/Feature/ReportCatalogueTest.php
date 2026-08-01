<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\BodyMeasurement;
use App\Models\Campaign;
use App\Models\ClassSession;
use App\Models\Coach;
use App\Models\Conversation;
use App\Models\GymClass;
use App\Models\Member;
use App\Models\Product;
use App\Models\Transaction;
use App\Reports\ReportParams;
use App\Reports\ReportRegistry;
use App\Services\BookingService;
use App\Services\CheckInService;
use App\Services\MembershipService;
use Tests\ClubTestCase;

/** Every report in the catalogue has to run, on real rows and on none. */
class ReportCatalogueTest extends ClubTestCase
{
    public function test_the_catalogue_offers_more_than_a_hundred_reports(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/reports')
            ->assertOk();

        $this->assertGreaterThanOrEqual(100, $response->json('total'));
        $this->assertNotEmpty($response->json('groups'));

        foreach ($response->json('reports') as $report) {
            $this->assertArrayHasKey('key', $report);
            $this->assertArrayHasKey('group', $report);
            $this->assertNotSame("reports.{$report['key']}", $report['label'], "{$report['key']} has no label");
        }
    }

    public function test_every_report_runs_against_an_empty_club(): void
    {
        $registry = app(ReportRegistry::class);
        $params = ReportParams::fromRequest(request());

        foreach (array_keys($registry->definitions()) as $key) {
            $data = $registry->run($key, $params);

            $this->assertNotNull($data, "{$key} returned nothing");
        }
    }

    public function test_every_report_runs_against_a_club_with_data(): void
    {
        $this->seedSomeActivity();

        $registry = app(ReportRegistry::class);
        $params = ReportParams::fromRequest(request());

        foreach (array_keys($registry->definitions()) as $key) {
            $data = $registry->run($key, $params);

            $this->assertNotNull($data, "{$key} returned nothing");
        }
    }

    public function test_an_unknown_report_key_is_a_404(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/reports/no-such-report')
            ->assertNotFound();
    }

    public function test_a_report_exports_as_csv(): void
    {
        $this->seedSomeActivity();

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->get('/api/v1/reports/member_directory/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    public function test_reports_are_refused_without_the_permission(): void
    {
        $this->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/reports')
            ->assertUnauthorized();
    }

    /** Enough of everything that every query has at least one row to chew on. */
    protected function seedSomeActivity(): void
    {
        $coach = Coach::create(['first_name' => 'Reza', 'last_name' => 'Coach', 'contract_type' => 'fixed', 'salary_amount' => 500]);

        $class = GymClass::create([
            'coach_id' => $coach->id,
            'name' => ['en' => 'Yoga'],
            'kind' => 'class',
            'capacity' => 10,
            'duration_minutes' => 60,
            'price' => 100,
        ]);

        $session = ClassSession::create([
            'gym_class_id' => $class->id,
            'coach_id' => $coach->id,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'capacity' => 10,
            'price' => 100,
            'status' => 'scheduled',
        ]);

        foreach ([['Ali', 'Ahmadi', 'male'], ['Sara', 'Karimi', 'female']] as $index => [$first, $last, $gender]) {
            $member = Member::create([
                'code' => '900'.$index,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => '0912000000'.$index,
                'gender' => $gender,
                'birth_date' => today()->subYears(30 + $index)->toDateString(),
                'blood_type' => 'O+',
                'diseases' => $index === 0 ? 'asthma' : null,
            ]);

            app(MembershipService::class)->sell($member, $this->plan($index === 0 ? 'duration' : 'session'));
            app(CheckInService::class)->checkIn($member);

            Attendance::where('member_id', $member->id)->update(['checked_out_at' => now()->addHour()]);

            BodyMeasurement::create(['member_id' => $member->id, 'measured_at' => today()->subMonth(), 'weight' => 80, 'bmi' => 25, 'fat_percent' => 22, 'muscle_mass' => 35]);
            BodyMeasurement::create(['member_id' => $member->id, 'measured_at' => today(), 'weight' => 77, 'bmi' => 24, 'fat_percent' => 20, 'muscle_mass' => 36]);

            Conversation::create(['member_id' => $member->id, 'coach_id' => $coach->id, 'last_message_at' => now()])
                ->messages()->create([
                    'tenant_id' => $this->club->id,
                    'sender_type' => 'member',
                    'sender_id' => $this->owner->id,
                    'body' => 'Hello coach',
                ]);

            if ($index === 0) {
                app(BookingService::class)->book($session, $member);
            }
        }

        Product::create(['name' => 'Whey', 'sku' => 'WH-1', 'category' => 'supplement', 'price' => 200, 'cost' => 120, 'stock' => 2, 'min_stock' => 5]);

        Transaction::create([
            'type' => Transaction::EXPENSE,
            'category' => 'salary',
            'amount' => 500,
            'method' => 'cash',
            'description' => 'Coach salary',
            'reference_type' => Coach::class,
            'reference_id' => $coach->id,
            'occurred_at' => now(),
        ]);

        Campaign::create([
            'title' => 'Renewal push',
            'channel' => 'sms',
            'body' => 'Time to renew',
            'audience' => ['type' => 'expiring', 'days' => 7],
            'status' => 'sent',
            'sent_at' => now(),
            'recipients_count' => 2,
            'delivered_count' => 2,
            'created_by' => $this->owner->id,
        ]);
    }
}
