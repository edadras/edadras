<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\ClubTestCase;

/**
 * Signing in with a provider. The rule that matters: a Google account on its
 * own must never let a stranger into a club that did not invite them.
 */
class OAuthTest extends ClubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client',
            'services.google.client_secret' => 'test-secret',
            'services.google.redirect' => 'http://localhost/api/v1/auth/oauth/google/callback',
        ]);
    }

    /** The state the last primed redirect minted. */
    protected ?string $state = null;

    /**
     * One driver mock standing in for the whole round trip — redirect and
     * callback both go through the same facade, so faking them separately
     * just replaces one with the other.
     */
    protected function fakeProvider(string $id, ?string $email, string $name = 'Provider Person'): void
    {
        $identity = (new SocialiteUser)->map([
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'avatar' => 'https://example.test/a.png',
        ]);

        $driver = Mockery::mock('Laravel\Socialite\Contracts\Provider');
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn($identity);
        $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.test'));
        $driver->shouldReceive('with')->andReturnUsing(function (array $options) use (&$driver) {
            $this->state = $options['state'];

            return $driver;
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
    }

    /** Runs the redirect for real and returns the state it minted. */
    protected function primeState(): string
    {
        $this->get("/api/v1/auth/oauth/google/redirect?tenant={$this->club->slug}")->assertRedirect();

        return $this->state;
    }

    public function test_only_providers_with_credentials_are_offered(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/auth/oauth')
            ->assertOk();

        $providers = collect($response->json('providers'))->pluck('provider');

        $this->assertContains('google', $providers);
        $this->assertNotContains('apple', $providers, 'apple has no credentials configured');
    }

    public function test_an_unconfigured_provider_is_a_404(): void
    {
        $this->get('/api/v1/auth/oauth/apple/redirect')->assertNotFound();
    }

    public function test_the_redirect_sends_the_browser_to_the_provider(): void
    {
        $this->fakeProvider('g-1', 'owner@test.club');

        $this->get("/api/v1/auth/oauth/google/redirect?tenant={$this->club->slug}")
            ->assertRedirect('https://accounts.google.test');
    }

    public function test_a_callback_without_a_live_state_is_refused(): void
    {
        $this->fakeProvider('g-1', 'owner@test.club');

        $this->getJson('/api/v1/auth/oauth/google/callback?state=made-up')
            ->assertStatus(422)
            ->assertJsonPath('reason', 'oauth_state_expired');
    }

    public function test_a_stranger_cannot_walk_into_a_club_that_never_invited_them(): void
    {
        config(['gymflow.oauth.allow_self_signup' => false]);

        $this->fakeProvider('g-stranger', 'nobody@example.test');
        $state = $this->primeState();

        $this->getJson("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'oauth_no_account');

        $this->assertDatabaseCount('social_accounts', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.oauth_refused']);
    }

    public function test_a_provider_identity_matching_an_invited_account_signs_in(): void
    {
        $this->fakeProvider('g-owner', $this->owner->email, 'Club Owner');
        $state = $this->primeState();

        $response = $this->getJson("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($this->owner->id, $response->json('user.id'));

        // The link is remembered, so next time no email match is needed.
        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'google',
            'provider_user_id' => 'g-owner',
            'user_id' => $this->owner->id,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $this->owner->id]);
    }

    public function test_an_already_linked_account_signs_in_even_with_a_new_email(): void
    {
        SocialAccount::create([
            'tenant_id' => $this->club->id,
            'user_id' => $this->owner->id,
            'provider' => 'google',
            'provider_user_id' => 'g-linked',
        ]);

        $this->fakeProvider('g-linked', 'changed@elsewhere.test');
        $state = $this->primeState();

        $this->getJson("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertOk()
            ->assertJsonPath('user.id', $this->owner->id);
    }

    public function test_self_signup_creates_a_member_never_staff(): void
    {
        config(['gymflow.oauth.allow_self_signup' => true]);

        $this->fakeProvider('g-new', 'newcomer@example.test', 'Newcomer Person');
        $state = $this->primeState();

        $response = $this->getJson("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertOk();

        $user = User::where('email', 'newcomer@example.test')->firstOrFail();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($this->club->id, $user->tenant_id);
        $this->assertNotNull($user->member, 'self signup should create a member profile');
        $this->assertSame(['member'], $user->roles->pluck('slug')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.oauth_registered']);
    }

    public function test_a_disabled_account_is_still_refused(): void
    {
        $this->owner->update(['status' => 'disabled']);

        $this->fakeProvider('g-owner', $this->owner->email);
        $state = $this->primeState();

        $this->getJson("/api/v1/auth/oauth/google/callback?state={$state}")
            ->assertStatus(422)
            ->assertJsonPath('reason', 'account_disabled');
    }

    public function test_a_signed_in_user_links_and_unlinks_a_provider(): void
    {
        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/oauth/google/link', [
                'provider_user_id' => 'g-manual',
                'email' => 'owner@test.club',
            ])
            ->assertCreated();

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/auth/oauth/linked')
            ->assertOk()
            ->assertJsonPath('0.provider', 'google');

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->deleteJson('/api/v1/auth/oauth/google')
            ->assertOk();

        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_a_provider_account_cannot_be_claimed_twice(): void
    {
        $other = User::create([
            'tenant_id' => $this->club->id,
            'name' => 'Someone Else',
            'email' => 'else@test.club',
            'password' => 'password',
        ]);

        SocialAccount::create([
            'tenant_id' => $this->club->id,
            'user_id' => $other->id,
            'provider' => 'google',
            'provider_user_id' => 'g-taken',
        ]);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/oauth/google/link', ['provider_user_id' => 'g-taken'])
            ->assertStatus(422);
    }
}
