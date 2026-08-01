<?php

namespace Tests\Feature;

use App\Support\TwoFactor;
use Illuminate\Support\Facades\Hash;
use Tests\ClubTestCase;

/** Two factor sign-in: the password stops being enough. */
class TwoFactorTest extends ClubTestCase
{
    protected TwoFactor $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = app(TwoFactor::class);
    }

    /** Turns it on the way a real owner would, and hands back the secret. */
    protected function turnOn(): string
    {
        $secret = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor')
            ->assertOk()
            ->json('secret');

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->totp->currentCode($secret)])
            ->assertOk();

        return $secret;
    }

    public function test_the_generated_code_matches_a_known_rfc_vector(): void
    {
        // RFC 6238 test vector: the ASCII secret "12345678901234567890",
        // base32 encoded, at counter 1 gives 287082.
        $this->assertSame('287082', $this->totp->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
    }

    public function test_enabling_hands_back_a_secret_a_qr_and_recovery_codes(): void
    {
        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor')
            ->assertOk();

        $this->assertNotEmpty($response->json('secret'));
        $this->assertStringContainsString('otpauth://totp/', $response->json('otpauth_url'));
        $this->assertStringContainsString('<svg', $response->json('qr_svg'));
        $this->assertCount(8, $response->json('recovery_codes'));

        // Issued but not switched on: a mis-scan must not lock the owner out.
        $this->assertFalse((bool) $this->owner->fresh()->two_factor_enabled);
    }

    public function test_recovery_codes_are_stored_hashed_never_in_the_clear(): void
    {
        $codes = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor')
            ->json('recovery_codes');

        $stored = $this->owner->fresh()->two_factor_recovery_codes;

        $this->assertNotContains($codes[0], $stored);
        $this->assertTrue(Hash::check($codes[0], $stored[0]));
    }

    public function test_a_wrong_code_does_not_switch_it_on(): void
    {
        $this->asUser()->withHeaders($this->clubHeaders())->postJson('/api/v1/auth/two-factor');

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertFalse((bool) $this->owner->fresh()->two_factor_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_failed']);
    }

    public function test_a_correct_code_switches_it_on(): void
    {
        $this->turnOn();

        $user = $this->owner->fresh();

        $this->assertTrue((bool) $user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.two_factor_enabled']);
    }

    public function test_the_password_alone_no_longer_signs_in(): void
    {
        $this->turnOn();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ], $this->clubHeaders())->assertStatus(202);

        $this->assertTrue($response->json('two_factor_required'));
        $this->assertNotEmpty($response->json('challenge'));
        $this->assertNull($response->json('token'));
    }

    public function test_the_code_completes_the_sign_in(): void
    {
        $secret = $this->turnOn();

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ], $this->clubHeaders())->json('challenge');

        $response = $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge' => $challenge,
            'code' => $this->totp->currentCode($secret),
        ], $this->clubHeaders())->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($this->owner->id, $response->json('user.id'));
    }

    public function test_a_challenge_is_good_for_one_sign_in_only(): void
    {
        $secret = $this->turnOn();

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ], $this->clubHeaders())->json('challenge');

        $body = ['challenge' => $challenge, 'code' => $this->totp->currentCode($secret)];

        $this->postJson('/api/v1/auth/two-factor/challenge', $body, $this->clubHeaders())->assertOk();
        $this->postJson('/api/v1/auth/two-factor/challenge', $body, $this->clubHeaders())->assertStatus(422);
    }

    public function test_a_wrong_code_at_the_challenge_hands_out_no_token(): void
    {
        $this->turnOn();

        $challenge = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ], $this->clubHeaders())->json('challenge');

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge' => $challenge,
            'code' => '111111',
        ], $this->clubHeaders())->assertStatus(422);
    }

    public function test_an_unknown_challenge_is_refused(): void
    {
        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge' => 'made-up',
            'code' => '123456',
        ], $this->clubHeaders())->assertStatus(422);
    }

    public function test_a_recovery_code_signs_in_once_and_is_then_spent(): void
    {
        $this->asUser()->withHeaders($this->clubHeaders())->postJson('/api/v1/auth/two-factor');
        $codes = $this->owner->fresh()->two_factor_recovery_codes;
        $secret = $this->owner->fresh()->two_factor_secret;

        $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->totp->currentCode($secret)])
            ->assertOk();

        // Re-issue so the plain codes are in hand for the test.
        $plain = $this->asUser()->withHeaders($this->clubHeaders())
            ->postJson('/api/v1/auth/two-factor/recovery-codes')
            ->assertOk()
            ->json('recovery_codes');

        $challenge = fn () => $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
        ], $this->clubHeaders())->json('challenge');

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge' => $challenge(),
            'recovery_code' => $plain[0],
        ], $this->clubHeaders())->assertOk();

        $this->assertCount(7, $this->owner->fresh()->two_factor_recovery_codes);

        $this->postJson('/api/v1/auth/two-factor/challenge', [
            'challenge' => $challenge(),
            'recovery_code' => $plain[0],
        ], $this->clubHeaders())->assertStatus(422);

        $this->assertCount(count($codes) - 1, $this->owner->fresh()->two_factor_recovery_codes);
    }

    public function test_turning_it_off_needs_the_password(): void
    {
        $this->turnOn();

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->deleteJson('/api/v1/auth/two-factor', ['password' => 'wrong'])
            ->assertStatus(422);

        $this->assertTrue((bool) $this->owner->fresh()->two_factor_enabled);

        $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->deleteJson('/api/v1/auth/two-factor', ['password' => 'password'])
            ->assertOk();

        $user = $this->owner->fresh();

        $this->assertFalse((bool) $user->two_factor_enabled);
        $this->assertNull($user->two_factor_secret);
    }

    public function test_the_secret_never_leaves_in_a_user_payload(): void
    {
        $this->turnOn();

        $response = $this->asUser()
            ->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertArrayNotHasKey('two_factor_secret', $response->json('user'));
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $response->json('user'));
    }

    public function test_the_secret_is_encrypted_at_rest(): void
    {
        $this->turnOn();

        $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $this->owner->id)->value('two_factor_secret');

        $this->assertNotSame($this->owner->fresh()->two_factor_secret, $raw);
    }

    public function test_the_status_endpoint_reports_what_is_on(): void
    {
        $this->asUser()->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/auth/two-factor')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('pending', false);

        $this->turnOn();

        $this->asUser()->withHeaders($this->clubHeaders())
            ->getJson('/api/v1/auth/two-factor')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('recovery_codes_left', 8);
    }
}
