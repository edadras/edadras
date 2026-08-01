<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Auditor;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Two factor authentication over TOTP. Turning it on is two steps on purpose:
 * a secret is issued, and only a code proving the phone actually scanned it
 * switches the second factor on. Otherwise a mis-scan locks the owner out of
 * their own club.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactor $totp,
        private readonly Auditor $auditor,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => (bool) $user->two_factor_enabled,
            'confirmed_at' => $user->two_factor_confirmed_at?->toDateTimeString(),
            'pending' => filled($user->two_factor_secret) && ! $user->two_factor_enabled,
            'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    /** Issues a secret and the QR to scan. Nothing is switched on yet. */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user->two_factor_enabled, 422, __('auth.two_factor_already_on'));

        $secret = $this->totp->generateSecret();
        $codes = $this->totp->recoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $codes),
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();

        $uri = $this->totp->provisioningUri(
            $secret,
            $user->email ?? $user->phone ?? (string) $user->id,
            $user->tenant?->name ?? config('app.name'),
        );

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $uri,
            'qr_svg' => (string) QrCode::format('svg')->size(280)->margin(1)->generate($uri),
            // Shown exactly once. Only hashes are kept.
            'recovery_codes' => $codes,
        ]);
    }

    /** A correct code proves the phone has the secret; now it counts. */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string']]);

        abort_if(blank($user->two_factor_secret), 422, __('auth.two_factor_not_started'));

        if (! $this->totp->verify($user->two_factor_secret, $data['code'])) {
            $this->auditor->log('auth.two_factor_failed', $user);

            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->auditor->log('auth.two_factor_enabled', $user);

        return response()->json(['message' => __('auth.two_factor_enabled')]);
    }

    /** Switching it off asks for the password, not just the session. */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => __('auth.password_mismatch')]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->auditor->log('auth.two_factor_disabled', $user);

        return response()->json(['message' => __('auth.two_factor_disabled')]);
    }

    /** Issues a fresh set and voids the old one. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user->two_factor_enabled, 422, __('auth.two_factor_off'));

        $codes = $this->totp->recoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(fn (string $code) => Hash::make($code), $codes),
        ])->save();

        $this->auditor->log('auth.two_factor_recovery_regenerated', $user);

        return response()->json(['recovery_codes' => $codes]);
    }
}
