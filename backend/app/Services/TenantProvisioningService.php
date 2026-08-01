<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Onboarding. Creating a club builds its isolated space: roles, the owner
 * account, a starter price list and a subscription on the chosen SaaS plan.
 */
class TenantProvisioningService
{
    public function __construct(private readonly TenantContext $tenancy) {}

    /**
     * @param  array{name:string, type?:string, phone?:string, email?:string, address?:string, brand_color?:string, locale?:string, currency?:string, timezone?:string}  $club
     * @param  array{name:string, email:string, password:string, phone?:string}  $owner
     */
    public function create(array $club, array $owner, ?string $planSlug = 'free'): Tenant
    {
        return DB::transaction(function () use ($club, $owner, $planSlug) {
            $tenant = Tenant::create([
                'slug' => $this->uniqueSlug($club['name']),
                'name' => $club['name'],
                'type' => $club['type'] ?? 'gym',
                'phone' => $club['phone'] ?? null,
                'email' => $club['email'] ?? $owner['email'],
                'address' => $club['address'] ?? null,
                'brand_color' => $club['brand_color'] ?? '#5EF38C',
                'locale' => $club['locale'] ?? 'fa',
                'currency' => $club['currency'] ?? 'IRR',
                'timezone' => $club['timezone'] ?? 'Asia/Tehran',
                'status' => 'active',
            ]);

            return $this->tenancy->run($tenant, function () use ($tenant, $owner, $planSlug) {
                $roles = $this->seedRoles($tenant);
                $this->seedMembershipPlans($tenant);
                $this->subscribe($tenant, $planSlug);

                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $owner['name'],
                    'email' => $owner['email'],
                    'phone' => $owner['phone'] ?? null,
                    'password' => $owner['password'],
                    'locale' => $tenant->locale,
                    'status' => 'active',
                ]);

                $user->roles()->attach($roles[Role::OWNER]->id);

                return $tenant->fresh();
            });
        });
    }

    /** @return array<string, Role> */
    public function seedRoles(Tenant $tenant): array
    {
        $roles = [];

        foreach (Permissions::defaultRoles() as $slug => $definition) {
            $roles[$slug] = Role::updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => $slug],
                [
                    'name' => $definition['name'],
                    'permissions' => $definition['permissions'],
                    'is_system' => true,
                ],
            );
        }

        return $roles;
    }

    public function subscribe(Tenant $tenant, ?string $planSlug): ?TenantSubscription
    {
        $plan = Plan::where('slug', $planSlug ?? 'free')->first();

        if (! $plan) {
            return null;
        }

        $tenant->subscriptions()->where('status', 'active')->update(['status' => 'cancelled']);

        if ($plan->trial_days > 0) {
            $tenant->update(['trial_ends_at' => now()->addDays($plan->trial_days)]);
        }

        return TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'starts_at' => now(),
            'ends_at' => $plan->period === 'yearly' ? now()->addYear() : now()->addMonth(),
            'price' => $plan->price,
            'status' => $plan->trial_days > 0 ? 'trialing' : 'active',
        ]);
    }

    /** A club can sell nothing until it has a price list, so seed a sane one. */
    protected function seedMembershipPlans(Tenant $tenant): void
    {
        $defaults = [
            ['fa' => 'یک ماهه', 'tr' => 'Aylık', 'en' => 'Monthly', 'days' => 30, 'sessions' => null, 'price' => 3_000_000],
            ['fa' => 'سه ماهه', 'tr' => 'Üç Aylık', 'en' => '3 Months', 'days' => 90, 'sessions' => null, 'price' => 8_000_000],
            ['fa' => 'شش ماهه', 'tr' => 'Altı Aylık', 'en' => '6 Months', 'days' => 180, 'sessions' => null, 'price' => 15_000_000],
            ['fa' => 'سالانه', 'tr' => 'Yıllık', 'en' => 'Yearly', 'days' => 365, 'sessions' => null, 'price' => 27_000_000],
            ['fa' => '۱۰ جلسه', 'tr' => '10 Seans', 'en' => '10 Sessions', 'days' => 60, 'sessions' => 10, 'price' => 1_500_000],
            ['fa' => '۲۰ جلسه', 'tr' => '20 Seans', 'en' => '20 Sessions', 'days' => 90, 'sessions' => 20, 'price' => 2_800_000],
        ];

        foreach ($defaults as $index => $default) {
            MembershipPlan::create([
                'tenant_id' => $tenant->id,
                'name' => ['fa' => $default['fa'], 'tr' => $default['tr'], 'en' => $default['en']],
                'type' => $default['sessions'] ? MembershipPlan::TYPE_SESSION : MembershipPlan::TYPE_DURATION,
                'duration_days' => $default['days'],
                'session_count' => $default['sessions'],
                'price' => $default['price'],
                'sort_order' => $index,
            ]);
        }
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'club';
        $slug = $base;
        $suffix = 1;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    /** Enforces the seat limit of the club's SaaS plan. */
    public function canAddMember(Tenant $tenant): bool
    {
        $plan = $tenant->activeSubscription?->plan;

        if (! $plan || $plan->max_members === null) {
            return true;
        }

        return $tenant->members()->count() < $plan->max_members;
    }

    /** Convenience for the dashboard: does this club still hold usable passes. */
    public function activeMembershipCount(Tenant $tenant): int
    {
        return Membership::forTenant($tenant->id)->where('status', Membership::ACTIVE)->count();
    }
}
