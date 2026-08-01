<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Models\Translation;
use App\Models\User;
use App\Services\DatabaseTranslationLoader;
use App\Services\TenantProvisioningService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Super Admin panel: every club, the SaaS plans, platform revenue and
 * the global settings. Only users with is_super_admin reach these routes.
 */
class PlatformController extends Controller
{
    public function __construct(
        private readonly TenantProvisioningService $provisioning,
        private readonly TenantContext $tenancy,
    ) {}

    public function dashboard(): JsonResponse
    {
        $subscriptions = TenantSubscription::with('plan')->whereIn('status', ['active', 'trialing'])->get();

        return response()->json([
            'clubs' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', 'active')->count(),
                'suspended' => Tenant::where('status', 'suspended')->count(),
                'new_this_month' => Tenant::where('created_at', '>=', today()->startOfMonth())->count(),
                'by_type' => Tenant::select('type', DB::raw('count(*) as total'))->groupBy('type')->pluck('total', 'type'),
            ],
            'users' => [
                'staff' => User::whereNotNull('tenant_id')->count(),
                'members' => Member::withoutGlobalScopes()->count(),
            ],
            'revenue' => [
                'mrr' => round($subscriptions->sum(fn (TenantSubscription $s) => $s->plan->period === 'yearly'
                    ? (float) $s->price / 12
                    : (float) $s->price), 2),
                'by_plan' => $subscriptions->groupBy('plan_id')->map(fn ($rows) => [
                    'plan' => $rows->first()->plan->translate('name'),
                    'clubs' => $rows->count(),
                    'revenue' => round($rows->sum('price'), 2),
                ])->values(),
            ],
            'club_turnover_this_month' => round(
                Transaction::withoutGlobalScopes()
                    ->where('type', Transaction::INCOME)
                    ->where('occurred_at', '>=', today()->startOfMonth())
                    ->sum('amount'),
                2
            ),
        ]);
    }

    public function tenants(Request $request): JsonResponse
    {
        return response()->json(
            Tenant::query()
                ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
                ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
                ->when($request->query('type'), fn ($q, $t) => $q->where('type', $t))
                ->with('activeSubscription.plan')
                ->withCount('users')
                ->latest()
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function showTenant(Tenant $tenant): JsonResponse
    {
        // Counting a club's own rows means stepping into its scope.
        $stats = $this->tenancy->run($tenant, fn () => [
            'members' => Member::count(),
            'active_members' => Member::active()->count(),
            'revenue_this_month' => round(
                Transaction::income()->where('occurred_at', '>=', today()->startOfMonth())->sum('amount'),
                2
            ),
        ]);

        return response()->json([
            'club' => $tenant->load('activeSubscription.plan', 'subscriptions.plan'),
            'stats' => $stats,
        ]);
    }

    public function updateTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $tenant->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'pending'])],
            'trial_ends_at' => ['nullable', 'date'],
        ]));

        return response()->json($tenant->fresh());
    }

    public function subscribeTenant(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'exists:plans,slug']]);

        $subscription = $this->provisioning->subscribe($tenant, $data['plan']);

        return response()->json($subscription?->load('plan'), 201);
    }

    public function plans(): JsonResponse
    {
        return response()->json(Plan::orderBy('sort_order')->get());
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60', 'unique:plans,slug'],
            'name' => ['required', 'array'],
            'description' => ['nullable', 'array'],
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'period' => ['required', Rule::in(['monthly', 'yearly', 'lifetime'])],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'max_staff' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        return response()->json(Plan::create($data), 201);
    }

    public function updatePlan(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validate([
            'name' => ['sometimes', 'array'],
            'description' => ['nullable', 'array'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'trial_days' => ['nullable', 'integer', 'min:0'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'max_staff' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]));

        return response()->json($plan->fresh());
    }

    /**
     * The string templates every club inherits. A row with no tenant is the
     * platform default; a club that overrides one gets its own row underneath,
     * which is what makes "Members" read as "اعضا" in one club and something
     * the owner prefers in another.
     */
    public function templates(Request $request): JsonResponse
    {
        $locale = $request->query('locale', config('gymflow.default_locale'));

        abort_unless(array_key_exists($locale, config('gymflow.locales')), 404);

        $group = $request->query('group');
        $groups = $this->translatableGroups();

        abort_if($group && ! in_array($group, $groups, true), 404);

        $overrides = Translation::whereNull('tenant_id')
            ->where('locale', $locale)
            ->when($group, fn ($q) => $q->where('group', $group))
            ->get()
            ->keyBy(fn (Translation $row) => $row->group.'.'.$row->key);

        $perTenant = Translation::whereNotNull('tenant_id')
            ->where('locale', $locale)
            ->when($group, fn ($q) => $q->where('group', $group))
            ->get()
            ->groupBy(fn (Translation $row) => $row->group.'.'.$row->key)
            ->map->count();

        $lines = collect($group ? [$group] : $groups)
            ->flatMap(function (string $name) use ($locale, $overrides, $perTenant) {
                $file = trans($name, [], $locale);

                return collect(is_array($file) ? Arr::dot($file) : [])
                    ->map(function ($value, string $key) use ($name, $overrides, $perTenant) {
                        $path = $name.'.'.$key;

                        return [
                            'group' => $name,
                            'key' => $key,
                            'value' => (string) $value,
                            'overridden' => $overrides->has($path),
                            'clubs_overriding' => (int) ($perTenant[$path] ?? 0),
                        ];
                    })
                    ->values();
            });

        return response()->json([
            'locale' => $locale,
            'direction' => config("gymflow.locales.{$locale}.dir"),
            'groups' => $groups,
            'total' => $lines->count(),
            'lines' => $lines->when(
                $search = $request->query('search'),
                fn ($rows) => $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['key'].' '.$row['value']), mb_strtolower($search)))
            )->values(),
        ]);
    }

    /** Rewrites a platform default. Clubs that overrode it keep their own. */
    public function updateTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('gymflow.locales')))],
            'group' => ['required', Rule::in($this->translatableGroups())],
            'key' => ['required', 'string', 'max:190'],
            'value' => ['required', 'string', 'max:2000'],
        ]);

        $template = Translation::updateOrCreate(
            ['tenant_id' => null, 'locale' => $data['locale'], 'group' => $data['group'], 'key' => $data['key']],
            ['value' => $data['value']],
        );

        DatabaseTranslationLoader::flush();

        return response()->json($template, $template->wasRecentlyCreated ? 201 : 200);
    }

    /** Drops the override so the string falls back to what ships in the code. */
    public function resetTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(array_keys(config('gymflow.locales')))],
            'group' => ['required', 'string', 'max:60'],
            'key' => ['required', 'string', 'max:190'],
        ]);

        Translation::whereNull('tenant_id')
            ->where($data)
            ->delete();

        DatabaseTranslationLoader::flush();

        return response()->json(['message' => __('general.deleted')]);
    }

    /** How much of the platform's wording each club has rewritten. */
    public function templateOverrides(Request $request): JsonResponse
    {
        return response()->json(
            Translation::whereNotNull('tenant_id')
                ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
                ->when($request->query('locale'), fn ($q, $l) => $q->where('locale', $l))
                ->with('tenant:id,name,slug')
                ->latest('updated_at')
                ->paginate($request->integer('per_page', 50))
        );
    }

    /** @return array<int, string> */
    protected function translatableGroups(): array
    {
        return ['general', 'checkin', 'booking', 'auth', 'ai', 'reports', 'panel', 'crm', 'payments'];
    }

    public function auditLogs(Request $request): JsonResponse
    {
        return response()->json(
            AuditLog::query()
                ->when($request->query('tenant_id'), fn ($q, $id) => $q->where('tenant_id', $id))
                ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
                ->with('user:id,name', 'tenant:id,name')
                ->latest()
                ->paginate($request->integer('per_page', 50))
        );
    }
}
