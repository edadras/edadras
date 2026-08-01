<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Tests\ClubTestCase;

/** Who changed what, and who tried to sign in. */
class AuditTrailTest extends ClubTestCase
{
    public function test_creating_a_member_leaves_a_trail(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/members', [
                'first_name' => 'Nima',
                'last_name' => 'Audit',
                'phone' => '09121110000',
            ])
            ->assertCreated();

        $log = AuditLog::where('action', 'member.created')->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame($this->club->id, $log->tenant_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('Nima', $log->new_values['first_name']);
    }

    public function test_an_update_records_only_what_moved_and_what_it_was(): void
    {
        $member = Member::create(['code' => '2001', 'first_name' => 'Old', 'last_name' => 'Name', 'phone' => '09121110001']);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->putJson("/api/v1/members/{$member->id}", ['first_name' => 'New'])
            ->assertOk();

        $log = AuditLog::where('action', 'member.updated')->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame(['first_name' => 'Old'], $log->old_values);
        $this->assertSame(['first_name' => 'New'], $log->new_values);
    }

    public function test_secrets_never_reach_the_trail(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/club/staff', [
                'name' => 'New Cashier',
                'email' => 'cashier@test.club',
                'password' => 'super-secret-password',
                'roles' => [Role::where('tenant_id', $this->club->id)->where('slug', 'cashier')->value('id')],
            ])
            ->assertCreated();

        $log = AuditLog::where('action', 'user.created')->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame('••••', $log->new_values['password']);
        $this->assertStringNotContainsString('super-secret-password', json_encode($log->new_values));
    }

    public function test_a_failed_sign_in_is_recorded_with_the_address_it_came_from(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'not-the-password',
        ], $this->clubHeaders())->assertStatus(422);

        $log = AuditLog::where('action', 'auth.login_failed')->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame($this->owner->id, $log->auditable_id);
        $this->assertNotNull($log->ip_address);
    }

    public function test_a_successful_sign_in_is_recorded(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ], $this->clubHeaders())->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.login',
            'auditable_id' => $this->owner->id,
        ]);
    }

    public function test_the_club_reads_its_own_trail_and_nobody_else_s(): void
    {
        $other = app(TenantProvisioningService::class)->create(
            ['name' => 'Rival Club', 'type' => 'gym'],
            ['name' => 'Rival', 'email' => 'rival@rival.club', 'password' => 'password'],
            'free',
        );

        AuditLog::create(['tenant_id' => $other->id, 'action' => 'member.created']);
        AuditLog::create(['tenant_id' => $this->club->id, 'action' => 'member.created']);

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/club/audit-logs?action=member')
            ->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertSame($this->club->id, $row['tenant_id']);
        }
    }

    public function test_a_receptionist_cannot_read_the_trail(): void
    {
        $reception = User::create([
            'tenant_id' => $this->club->id,
            'name' => 'Front Desk',
            'email' => 'desk@test.club',
            'password' => 'password',
        ]);
        $reception->roles()->attach(
            Role::where('tenant_id', $this->club->id)->where('slug', 'reception')->value('id')
        );

        $this->actingAs($reception->fresh('roles'), 'sanctum')
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/club/audit-logs')
            ->assertForbidden();
    }
}
