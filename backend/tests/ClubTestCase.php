<?php

namespace Tests;

use App\Models\MembershipPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/** Base for tests that need a real, fully provisioned club. */
abstract class ClubTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $club;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->club = app(TenantProvisioningService::class)->create(
            ['name' => 'Test Club', 'type' => 'gym'],
            ['name' => 'Owner', 'email' => 'owner@test.club', 'password' => 'password'],
            'professional',
        );

        $this->owner = $this->club->users()->firstWhere('email', 'owner@test.club');

        app(TenantContext::class)->set($this->club);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();

        parent::tearDown();
    }

    /** Signs a request in as a user of this club. */
    protected function asUser(?User $user = null): static
    {
        $this->actingAs($user ?? $this->owner, 'sanctum');

        return $this;
    }

    /** Requests carry the club header the way the real apps do. */
    protected function clubHeaders(): array
    {
        return ['X-Tenant' => $this->club->slug, 'Accept' => 'application/json'];
    }

    protected function plan(string $type = 'duration'): MembershipPlan
    {
        return MembershipPlan::where('type', $type)->orderBy('sort_order')->firstOrFail();
    }
}
