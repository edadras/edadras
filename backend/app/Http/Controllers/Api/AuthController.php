<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioningService;
use App\Support\Auditor;
use App\Support\TwoFactor;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantContext $tenancy,
        private readonly Auditor $auditor,
        private readonly TwoFactor $totp,
    ) {}

    /**
     * Onboarding: a person creates their club and becomes its owner in one
     * request. This is the only endpoint that runs without a tenant.
     */
    public function registerClub(Request $request): JsonResponse
    {
        $data = $request->validate([
            'club.name' => ['required', 'string', 'max:120'],
            'club.type' => ['required', Rule::in(Tenant::TYPES)],
            'club.phone' => ['nullable', 'string', 'max:32'],
            'club.address' => ['nullable', 'string', 'max:500'],
            'club.brand_color' => ['nullable', 'string', 'max:9'],
            'club.locale' => ['nullable', Rule::in(array_keys(config('gymflow.locales')))],
            'club.currency' => ['nullable', 'string', 'size:3'],
            'club.timezone' => ['nullable', 'string', 'max:64'],
            'owner.name' => ['required', 'string', 'max:120'],
            'owner.email' => ['required', 'email', 'max:190'],
            'owner.phone' => ['nullable', 'string', 'max:32'],
            'owner.password' => ['required', 'string', 'min:8'],
            'plan' => ['nullable', 'string', 'exists:plans,slug'],
        ]);

        $tenant = $this->provisioning->create($data['club'], $data['owner'], $data['plan'] ?? 'free');

        $user = $tenant->users()->firstWhere('email', $data['owner']['email']);

        return response()->json([
            'club' => $tenant->load('activeSubscription.plan'),
            'user' => $user->load('roles'),
            'token' => $user->createToken('manager-app')->plainTextToken,
        ], 201);
    }

    /**
     * Staff login. The club is identified by the X-Tenant header, so the same
     * email can exist in two different clubs.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $query = User::query()->with('roles');

        if ($tenantId = $this->tenancy->id()) {
            $query->where('tenant_id', $tenantId);
        } else {
            // No club named: only a platform operator can sign in this way.
            $query->whereNull('tenant_id')->where('is_super_admin', true);
        }

        $user = $query->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->auditor->log('auth.login_failed', $user, [], ['email' => $credentials['email']], $user?->id);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if ($user->status !== 'active') {
            $this->auditor->log('auth.login_refused', $user, [], ['reason' => $user->status], $user->id);

            throw ValidationException::withMessages([
                'email' => __('auth.account_disabled'),
            ]);
        }

        // With a second factor on, the password alone earns a challenge, not
        // a token. Nothing is signed in until the code comes back.
        if ($user->two_factor_enabled) {
            return response()->json([
                'two_factor_required' => true,
                'challenge' => $this->issueChallenge($user, $credentials['device_name'] ?? 'api'),
                'message' => __('auth.two_factor_required'),
            ], 202);
        }

        return response()->json($this->grant($user, $credentials['device_name'] ?? 'api'));
    }

    /**
     * The second step: a TOTP code, or one of the recovery codes for the day
     * the phone is gone. A recovery code is spent the moment it is used.
     */
    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        $pending = Cache::get($this->challengeKey($data['challenge']));

        if (! $pending) {
            throw ValidationException::withMessages(['challenge' => __('auth.two_factor_expired')]);
        }

        $user = User::withoutGlobalScopes()->find($pending['user_id']);

        if (! $user || ! $user->two_factor_enabled) {
            throw ValidationException::withMessages(['challenge' => __('auth.two_factor_expired')]);
        }

        $passed = filled($data['code'] ?? null)
            ? $this->totp->verify($user->two_factor_secret, $data['code'])
            : $this->spendRecoveryCode($user, $data['recovery_code']);

        if (! $passed) {
            $this->auditor->log('auth.two_factor_failed', $user, [], [], $user->id);

            throw ValidationException::withMessages(['code' => __('auth.two_factor_invalid')]);
        }

        // One challenge, one sign-in.
        Cache::forget($this->challengeKey($data['challenge']));

        return response()->json($this->grant($user, $pending['device_name']));
    }

    /** Everything a signed in client needs, and the token to do it with. */
    protected function grant(User $user, string $deviceName): array
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditor->log('auth.login', $user, [], [
            'guard' => 'staff',
            'two_factor' => (bool) $user->two_factor_enabled,
        ], $user->id);

        return [
            'user' => $user,
            'permissions' => $user->permissions(),
            'club' => $user->tenant,
            'token' => $user->createToken($deviceName)->plainTextToken,
        ];
    }

    /** A five minute handle on a half-finished sign-in. */
    protected function issueChallenge(User $user, string $deviceName): string
    {
        $challenge = Str::random(48);

        Cache::put(
            $this->challengeKey($challenge),
            ['user_id' => $user->id, 'device_name' => $deviceName],
            now()->addMinutes(5),
        );

        return $challenge;
    }

    protected function challengeKey(string $challenge): string
    {
        return 'two-factor:'.hash('sha256', $challenge);
    }

    /** Checks a recovery code and burns it, so it works exactly once. */
    protected function spendRecoveryCode(User $user, ?string $candidate): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check((string) $candidate, $hash)) {
                unset($codes[$index]);

                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $this->auditor->log('auth.two_factor_recovery_used', $user, [], ['codes_left' => count($codes)], $user->id);

                return true;
            }
        }

        return false;
    }

    /** Member login for the member app, by phone plus password. */
    public function memberLogin(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        abort_unless($this->tenancy->check(), 400, __('general.club_required'));

        $user = User::where('tenant_id', $this->tenancy->id())
            ->where('phone', $credentials['phone'])
            ->whereHas('member')
            ->with('roles', 'member')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->auditor->log('auth.login_failed', $user, [], ['phone' => $credentials['phone']], $user?->id);

            throw ValidationException::withMessages(['phone' => __('auth.failed')]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $this->auditor->log('auth.login', $user, [], ['guard' => 'member'], $user->id);

        return response()->json([
            'user' => $user,
            'member' => $user->member,
            'club' => $user->tenant,
            'token' => $user->createToken('member-app')->plainTextToken,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('roles', 'member', 'coach');

        return response()->json([
            'user' => $user,
            'permissions' => $user->permissions(),
            'club' => $user->tenant,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        $this->auditor->log('auth.logout', $request->user());

        return response()->json(['message' => __('auth.logged_out')]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => __('auth.password_mismatch')]);
        }

        $user->update(['password' => $data['password']]);

        // A password change should not leave old phones signed in.
        $user->tokens()->delete();

        $this->auditor->log('auth.password_changed', $user);

        return response()->json([
            'message' => __('auth.password_updated'),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    /** Registers a device for push notifications. */
    public function registerPushToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['android', 'ios', 'web'])],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $token = $request->user()->pushTokens()->updateOrCreate(
            ['token' => $data['token']],
            [
                'tenant_id' => $request->user()->tenant_id,
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
            ],
        );

        return response()->json($token, 201);
    }
}
