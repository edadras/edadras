<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** The club's own profile, staff, roles and settings. */
class ClubController extends Controller
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function show(): JsonResponse
    {
        return response()->json(
            $this->tenancy->get()->load('activeSubscription.plan')
        );
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('settings.update');

        $tenant = $this->tenancy->get();

        $tenant->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', Rule::in(Tenant::TYPES)],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2'],
            'brand_color' => ['nullable', 'string', 'max:9'],
            'socials' => ['nullable', 'array'],
            'socials.instagram' => ['nullable', 'string', 'max:190'],
            'socials.telegram' => ['nullable', 'string', 'max:190'],
            'socials.whatsapp' => ['nullable', 'string', 'max:190'],
            'socials.website' => ['nullable', 'string', 'max:190'],
            'working_hours' => ['nullable', 'array'],
            'rules' => ['nullable', 'string', 'max:5000'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', Rule::in(array_keys(config('gymflow.locales')))],
            'currency' => ['nullable', 'string', 'size:3'],
        ]));

        return response()->json($tenant->fresh());
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $this->authorize('settings.update');

        $request->validate(['logo' => ['required', 'image', 'max:2048']]);

        $tenant = $this->tenancy->get();

        if ($tenant->logo_path) {
            Storage::disk('public')->delete($tenant->logo_path);
        }

        $path = $request->file('logo')->store("tenants/{$tenant->id}", 'public');
        $tenant->update(['logo_path' => $path]);

        return response()->json(['logo_path' => $path, 'url' => Storage::disk('public')->url($path)]);
    }

    public function settings(): JsonResponse
    {
        $this->authorize('settings.view');

        return response()->json(
            Setting::where('tenant_id', $this->tenancy->id())->pluck('value', 'key')
        );
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('settings.update');

        $data = $request->validate([
            'settings' => ['required', 'array'],
        ]);

        foreach ($data['settings'] as $key => $value) {
            Setting::updateOrCreate(
                ['tenant_id' => $this->tenancy->id(), 'key' => $key],
                ['value' => $value],
            );
        }

        return response()->json(['message' => __('general.saved')]);
    }

    public function staff(Request $request): JsonResponse
    {
        $this->authorize('staff.view');

        return response()->json(
            User::query()
                ->whereDoesntHave('member')
                ->with('roles')
                ->orderBy('name')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function storeStaff(Request $request): JsonResponse
    {
        $this->authorize('staff.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->where('tenant_id', $this->tenancy->id())],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:8'],
            'locale' => ['nullable', Rule::in(array_keys(config('gymflow.locales')))],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['exists:roles,id'],
        ]);

        $user = User::create(collect($data)->except('roles')->all() + [
            'tenant_id' => $this->tenancy->id(),
            'status' => 'active',
        ]);

        $user->roles()->sync($this->scopedRoleIds($data['roles']));

        return response()->json($user->load('roles'), 201);
    }

    public function updateStaff(Request $request, User $user): JsonResponse
    {
        $this->authorize('staff.update');

        abort_if($user->tenant_id !== $this->tenancy->id(), 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'min:8'],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'locale' => ['nullable', Rule::in(array_keys(config('gymflow.locales')))],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['exists:roles,id'],
        ]);

        $user->update(collect($data)->except('roles')->filter(fn ($value) => $value !== null)->all());

        if (isset($data['roles'])) {
            $user->roles()->sync($this->scopedRoleIds($data['roles']));
        }

        return response()->json($user->fresh('roles'));
    }

    public function destroyStaff(User $user): JsonResponse
    {
        $this->authorize('staff.delete');

        abort_if($user->tenant_id !== $this->tenancy->id(), 404);
        abort_if($user->hasRole(Role::OWNER), 422, __('general.cannot_delete_owner'));

        $user->delete();

        return response()->json(['message' => __('general.deleted')]);
    }

    public function roles(): JsonResponse
    {
        $this->authorize('roles.view');

        return response()->json([
            'roles' => Role::where('tenant_id', $this->tenancy->id())->get(),
            'permissions' => Permissions::all(),
            'groups' => Permissions::GROUPS,
        ]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorize('roles.create');

        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60', Rule::unique('roles', 'slug')->where('tenant_id', $this->tenancy->id())],
            'name' => ['required', 'array'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        return response()->json(Role::create($data + ['tenant_id' => $this->tenancy->id()]), 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $this->authorize('roles.update');

        abort_if($role->tenant_id !== $this->tenancy->id(), 404);
        abort_if($role->slug === Role::OWNER, 422, __('general.cannot_edit_owner_role'));

        $role->update($request->validate([
            'name' => ['sometimes', 'array'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]));

        return response()->json($role->fresh());
    }

    /**
     * The club's own trail: who changed what, inside this club only. The
     * platform panel has a wider view across every club.
     */
    public function auditLogs(Request $request): JsonResponse
    {
        $this->authorize('audit.view');

        return response()->json(
            AuditLog::where('tenant_id', $this->tenancy->id())
                ->when($request->query('action'), fn ($q, $action) => $q->where('action', 'like', $action.'%'))
                ->when($request->query('user_id'), fn ($q, $id) => $q->where('user_id', $id))
                ->when($request->date('from'), fn ($q, $from) => $q->where('created_at', '>=', $from))
                ->when($request->date('to'), fn ($q, $to) => $q->where('created_at', '<=', $to))
                ->with('user:id,name')
                ->latest()
                ->paginate($request->integer('per_page', 50))
        );
    }

    /** Role ids are validated to exist, then narrowed to this club's own. */
    protected function scopedRoleIds(array $ids): array
    {
        return Role::whereIn('id', $ids)
            ->where('tenant_id', $this->tenancy->id())
            ->pluck('id')
            ->all();
    }
}
