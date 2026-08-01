<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Tenancy\TenantContext;
use Tests\ClubTestCase;

/**
 * The promise of the platform: one club can never see another club's data,
 * whether through a query, an API call, or a guessed id.
 */
class TenantIsolationTest extends ClubTestCase
{
    protected Tenant $otherClub;

    protected Member $otherMember;

    protected function setUp(): void
    {
        parent::setUp();

        $tenancy = app(TenantContext::class);

        $this->otherClub = $tenancy->run(null, fn () => app(TenantProvisioningService::class)->create(
            ['name' => 'Rival Club', 'type' => 'pool'],
            ['name' => 'Rival Owner', 'email' => 'owner@rival.club', 'password' => 'password'],
            'basic',
        ));

        $this->otherMember = $tenancy->run($this->otherClub, fn () => Member::create([
            'code' => '9001',
            'first_name' => 'Rival',
            'last_name' => 'Member',
            'phone' => '09121110000',
        ]));
    }

    public function test_queries_only_return_the_active_club_rows(): void
    {
        Member::create(['code' => '1', 'first_name' => 'Ours', 'last_name' => 'Member', 'phone' => '09120000000']);

        $members = Member::all();

        $this->assertCount(1, $members);
        $this->assertSame('Ours', $members->first()->first_name);
    }

    public function test_created_rows_are_stamped_with_the_active_club(): void
    {
        $member = Member::create(['code' => '2', 'first_name' => 'A', 'last_name' => 'B', 'phone' => '09120000001']);

        $this->assertSame($this->club->id, $member->tenant_id);
    }

    public function test_a_member_of_another_club_is_not_reachable_by_id(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson("/api/v1/members/{$this->otherMember->id}");

        $response->assertNotFound();
    }

    public function test_the_member_list_never_leaks_across_clubs(): void
    {
        Member::create(['code' => '3', 'first_name' => 'Ours', 'last_name' => 'Member', 'phone' => '09120000002']);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/members');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Ours', $response->json('data.0.first_name'));
    }

    public function test_the_same_email_can_own_an_account_in_two_clubs(): void
    {
        $this->assertNotSame($this->club->id, $this->otherClub->id);

        $duplicate = app(TenantContext::class)->run($this->otherClub, fn () => User::create([
            'tenant_id' => $this->otherClub->id,
            'name' => 'Same Person',
            'email' => 'owner@test.club',
            'password' => 'password',
        ]));

        $this->assertSame($this->otherClub->id, $duplicate->tenant_id);
    }

    public function test_a_suspended_club_stops_serving_its_apps(): void
    {
        $this->club->update(['status' => 'suspended']);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/dashboard')
            ->assertForbidden();
    }

    public function test_running_as_another_club_restores_the_previous_one(): void
    {
        $tenancy = app(TenantContext::class);

        $tenancy->run($this->otherClub, function () use ($tenancy) {
            $this->assertSame($this->otherClub->id, $tenancy->id());
        });

        $this->assertSame($this->club->id, $tenancy->id());
    }
}
