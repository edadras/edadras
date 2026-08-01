<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\ClubTestCase;

/** The seven system roles and what each of them may reach. */
class PermissionTest extends ClubTestCase
{
    protected function staff(string $roleSlug): User
    {
        $user = User::create([
            'tenant_id' => $this->club->id,
            'name' => ucfirst($roleSlug),
            'email' => "{$roleSlug}@test.club",
            'password' => 'password',
        ]);

        $user->roles()->attach(Role::where('tenant_id', $this->club->id)->where('slug', $roleSlug)->value('id'));

        return $user->fresh('roles');
    }

    public function test_a_new_club_gets_the_seven_system_roles(): void
    {
        $slugs = Role::where('tenant_id', $this->club->id)->pluck('slug')->sort()->values()->all();

        $this->assertSame(
            ['accountant', 'cashier', 'coach', 'manager', 'member', 'owner', 'reception'],
            $slugs,
        );
    }

    public function test_the_owner_holds_every_permission(): void
    {
        $this->assertTrue($this->owner->hasRole(Role::OWNER));
        $this->assertTrue($this->owner->hasPermission('finance.close_register'));
        $this->assertTrue($this->owner->hasPermission('roles.delete'));
    }

    public function test_a_wildcard_covers_its_whole_group(): void
    {
        $manager = $this->staff('manager');

        $this->assertTrue($manager->hasPermission('members.create'));
        $this->assertTrue($manager->hasPermission('members.delete'));
    }

    public function test_reception_can_run_the_gate_but_not_the_books(): void
    {
        $reception = $this->staff('reception');

        $this->assertTrue($reception->hasPermission('attendance.checkin'));
        $this->assertTrue($reception->hasPermission('members.create'));
        $this->assertFalse($reception->hasPermission('accounting.view'));
        $this->assertFalse($reception->hasPermission('staff.create'));
    }

    public function test_a_coach_cannot_touch_the_cash_box(): void
    {
        $coach = $this->staff('coach');

        $this->assertTrue($coach->hasPermission('workouts.create'));
        $this->assertFalse($coach->hasPermission('finance.create'));

        $this->actingAs($coach, 'sanctum')
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/finance/expenses', ['amount' => 100, 'category' => 'rent'])
            ->assertForbidden();
    }

    public function test_an_accountant_reaches_the_reports(): void
    {
        $accountant = $this->staff('accountant');

        $this->actingAs($accountant, 'sanctum')
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/reports')
            ->assertOk();
    }

    public function test_a_member_role_cannot_open_the_manager_screens(): void
    {
        $member = $this->staff('member');

        $this->actingAs($member, 'sanctum')
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/members')
            ->assertForbidden();
    }

    public function test_club_staff_cannot_reach_the_platform_panel(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/platform/tenants')
            ->assertForbidden();
    }

    public function test_a_super_admin_reaches_the_platform_panel(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@gymflow.ai',
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/platform/tenants')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_the_owner_account_cannot_be_deleted(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->deleteJson("/api/v1/club/staff/{$this->owner->id}")
            ->assertStatus(422);
    }

    public function test_a_role_from_another_club_cannot_be_attached(): void
    {
        $foreignRole = Role::create([
            'tenant_id' => null,
            'slug' => 'ghost',
            'name' => ['en' => 'Ghost'],
            'permissions' => ['*'],
        ]);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/club/staff', [
                'name' => 'New Person',
                'email' => 'new@test.club',
                'password' => 'password',
                'roles' => [$foreignRole->id],
            ]);

        $response->assertCreated();
        $this->assertCount(0, $response->json('roles'));
    }
}
