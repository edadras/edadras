<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auditor;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Signing in with Google, Apple or GitHub.
 *
 * Two rules make this safe in a multi-tenant platform. A provider account is
 * bound to one user in one club, never shared across clubs. And a provider
 * identity on its own never creates a staff account — it can only attach to
 * someone the club already invited, or create a member when the club has
 * turned that on. Otherwise anyone with a Google account could walk in.
 */
class OAuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenancy,
        private readonly Auditor $auditor,
    ) {}

    /** Which providers this deployment has credentials for. */
    public function providers(): JsonResponse
    {
        return response()->json([
            'providers' => collect($this->enabled())
                ->map(fn (string $provider) => [
                    'provider' => $provider,
                    'label' => __("auth.provider_{$provider}"),
                    'url' => route('oauth.redirect', [
                        'provider' => $provider,
                        'tenant' => $this->tenancy->get()?->slug,
                    ]),
                ])
                ->values(),
        ]);
    }

    /**
     * Sends the browser to the provider. The club travels in a short-lived
     * state token rather than the URL, so the callback cannot be pointed at
     * another club by editing a query string.
     */
    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, $this->enabled(), true), 404, __('auth.provider_unavailable'));

        $state = Str::random(48);

        Cache::put($this->stateKey($state), [
            'tenant' => $request->query('tenant'),
            'return' => $request->query('return'),
        ], now()->addMinutes(10));

        return Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    /** Where the provider sends the browser back. */
    public function callback(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($provider, $this->enabled(), true), 404);

        $pending = Cache::pull($this->stateKey((string) $request->query('state')));

        if (! $pending) {
            return $this->fail($request, null, 'oauth_state_expired');
        }

        $club = $pending['tenant'] ? Tenant::where('slug', $pending['tenant'])->first() : null;

        return $this->tenancy->run($club, function () use ($request, $provider, $pending, $club) {
            try {
                $identity = Socialite::driver($provider)->stateless()->user();
            } catch (Throwable) {
                return $this->fail($request, $pending['return'] ?? null, 'oauth_failed');
            }

            $user = $this->resolveUser($provider, $identity, $club);

            if (! $user) {
                $this->auditor->log('auth.oauth_refused', null, [], [
                    'provider' => $provider,
                    'email' => $identity->getEmail(),
                ]);

                return $this->fail($request, $pending['return'] ?? null, 'oauth_no_account');
            }

            if ($user->status !== 'active') {
                return $this->fail($request, $pending['return'] ?? null, 'account_disabled');
            }

            $user->forceFill(['last_login_at' => now()])->save();

            $this->auditor->log('auth.login', $user, [], ['guard' => 'oauth', 'provider' => $provider], $user->id);

            $token = $user->createToken("oauth-{$provider}")->plainTextToken;

            if ($return = $pending['return'] ?? null) {
                return redirect()->away($return.(str_contains($return, '?') ? '&' : '?').http_build_query([
                    'token' => $token,
                    'tenant' => $user->tenant?->slug,
                ]));
            }

            return response()->json([
                'user' => $user->load('roles'),
                'permissions' => $user->permissions(),
                'club' => $user->tenant,
                'token' => $token,
            ]);
        });
    }

    /** Links a provider to the account already signed in. */
    public function link(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, $this->enabled(), true), 404);

        $data = $request->validate([
            'provider_user_id' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $taken = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $data['provider_user_id'])
            ->where('user_id', '!=', $request->user()->id)
            ->exists();

        abort_if($taken, 422, __('auth.oauth_already_linked'));

        $account = SocialAccount::updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => $data['provider_user_id']],
            [
                'tenant_id' => $request->user()->tenant_id,
                'user_id' => $request->user()->id,
                'email' => $data['email'] ?? null,
            ],
        );

        $this->auditor->log('auth.oauth_linked', $account, [], ['provider' => $provider]);

        return response()->json($account, 201);
    }

    public function unlink(Request $request, string $provider): JsonResponse
    {
        $request->user()->socialAccounts()->where('provider', $provider)->delete();

        $this->auditor->log('auth.oauth_unlinked', $request->user(), [], ['provider' => $provider]);

        return response()->json(['message' => __('general.deleted')]);
    }

    public function linked(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->socialAccounts()->get(['id', 'provider', 'email', 'created_at'])
        );
    }

    /**
     * A provider identity resolves to an existing link, then to a club member
     * or staff account with the same email. It creates an account only when
     * the club has opted in, and only ever a member — never staff.
     */
    protected function resolveUser(string $provider, mixed $identity, ?Tenant $club): ?User
    {
        $linked = SocialAccount::where('provider', $provider)
            ->where('provider_user_id', $identity->getId())
            ->first();

        if ($linked) {
            return $linked->user;
        }

        $email = $identity->getEmail();

        if (blank($email)) {
            return null;
        }

        $existing = User::query()
            ->when($club, fn ($q) => $q->where('tenant_id', $club->id), fn ($q) => $q->whereNull('tenant_id'))
            ->where('email', $email)
            ->first();

        if ($existing) {
            $this->remember($provider, $identity, $existing);

            return $existing;
        }

        return $club && $this->selfSignupAllowed() ? $this->createMember($identity, $club, $provider) : null;
    }

    protected function createMember(mixed $identity, Tenant $club, string $provider): User
    {
        $user = User::create([
            'tenant_id' => $club->id,
            'name' => $identity->getName() ?: Str::before((string) $identity->getEmail(), '@'),
            'email' => $identity->getEmail(),
            // No password: this account can only ever come in through the
            // provider until someone sets one.
            'password' => Str::random(64),
            'status' => 'active',
        ]);

        $user->roles()->attach(
            Role::where('tenant_id', $club->id)->where('slug', Role::MEMBER)->value('id')
        );

        $user->member()->create([
            'tenant_id' => $club->id,
            'code' => (string) (10000 + $user->id),
            'first_name' => Str::before($user->name, ' ') ?: $user->name,
            'last_name' => Str::after($user->name, ' ') ?: '—',
            'email' => $user->email,
            'phone' => 'oauth-'.$user->id,
        ]);

        $this->remember($provider, $identity, $user);
        $this->auditor->log('auth.oauth_registered', $user, [], ['provider' => $provider], $user->id);

        return $user;
    }

    protected function remember(string $provider, mixed $identity, User $user): void
    {
        SocialAccount::updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => $identity->getId()],
            [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'email' => $identity->getEmail(),
                'name' => $identity->getName(),
                'avatar' => $identity->getAvatar(),
            ],
        );
    }

    protected function selfSignupAllowed(): bool
    {
        return (bool) config('gymflow.oauth.allow_self_signup', false);
    }

    /** @return array<int, string> */
    protected function enabled(): array
    {
        return collect((array) config('gymflow.oauth.providers', []))
            ->filter(fn (string $provider) => filled(config("services.{$provider}.client_id")))
            ->values()
            ->all();
    }

    protected function stateKey(string $state): string
    {
        return 'oauth:'.hash('sha256', $state);
    }

    protected function fail(Request $request, ?string $return, string $reason): RedirectResponse|JsonResponse
    {
        if ($return) {
            return redirect()->away($return.(str_contains($return, '?') ? '&' : '?').'error='.$reason);
        }

        return response()->json(['message' => __("auth.{$reason}"), 'reason' => $reason], 422);
    }
}
