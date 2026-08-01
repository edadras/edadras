<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\ClubTestCase;

/** How the API behaves at its edges, regardless of what the client sends. */
class ApiSurfaceTest extends ClubTestCase
{
    public function test_a_bad_password_answers_in_json_even_without_an_accept_header(): void
    {
        // A phone or a script that forgets the header used to get a 302 to an
        // HTML page instead of an error it could read.
        $response = $this->post(
            '/api/v1/auth/login',
            ['email' => $this->owner->email, 'password' => 'wrong'],
            ['X-Tenant' => $this->club->slug],
        );

        $response->assertStatus(422);
        $this->assertJson($response->getContent());
        $response->assertJsonStructure(['message', 'errors']);
    }

    public function test_an_unauthenticated_call_answers_in_json_too(): void
    {
        $response = $this->get('/api/v1/dashboard', ['X-Tenant' => $this->club->slug]);

        $response->assertStatus(401);
        $this->assertJson($response->getContent());
    }

    public function test_a_missing_route_answers_in_json(): void
    {
        $response = $this->get('/api/v1/no-such-thing', ['X-Tenant' => $this->club->slug]);

        $response->assertStatus(404);
        $this->assertJson($response->getContent());
    }

    /**
     * A club where reception, the manager and three coaches share one office
     * connection must not have them throttling each other.
     */
    public function test_the_rate_limit_counts_per_user_not_per_address(): void
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

        $limit = (int) config('gymflow.rate_limit.per_user');

        // Spend the owner's whole allowance from this one address.
        for ($i = 0; $i < $limit + 1; $i++) {
            $response = $this->asUser()
                ->withHeaders($this->clubHeaders())
                ->getJson('/api/v1/dashboard');
        }

        $response->assertStatus(429);

        // Reception, on the same address, is untouched.
        $this->actingAs($reception->fresh('roles'), 'sanctum')
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/attendance/inside')
            ->assertOk();
    }

    public function test_anonymous_traffic_has_its_own_smaller_bucket(): void
    {
        $limit = (int) config('gymflow.rate_limit.per_ip');

        $this->assertLessThan(
            (int) config('gymflow.rate_limit.per_user'),
            $limit,
            'guests should be held to a tighter limit than signed in staff'
        );

        for ($i = 0; $i < $limit + 1; $i++) {
            $response = $this->getJson('/api/v1/locales');
        }

        $response->assertStatus(429);
    }
}
