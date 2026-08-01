<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MembershipController extends Controller
{
    public function __construct(private readonly MembershipService $memberships) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('memberships.view');

        $memberships = Membership::query()
            ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->integer('expiring_within'), fn ($q, $days) => $q->expiringWithin($days))
            ->with('member', 'plan')
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return response()->json($memberships);
    }

    /** Sells a plan to a member and raises the matching invoice. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('memberships.create');

        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'],
            'membership_plan_id' => ['required', 'exists:membership_plans,id'],
            'starts_at' => ['nullable', 'date'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'create_invoice' => ['nullable', 'boolean'],
        ]);

        $membership = $this->memberships->sell(
            Member::findOrFail($data['member_id']),
            MembershipPlan::findOrFail($data['membership_plan_id']),
            $data,
        );

        return response()->json($membership->load('member', 'plan'), 201);
    }

    public function show(Membership $membership): JsonResponse
    {
        $this->authorize('memberships.view');

        return response()->json($membership->load('member', 'plan', 'attendances'));
    }

    public function renew(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('memberships.create');

        $data = $request->validate([
            'membership_plan_id' => ['nullable', 'exists:membership_plans,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $plan = isset($data['membership_plan_id'])
            ? MembershipPlan::findOrFail($data['membership_plan_id'])
            : null;

        $renewed = $this->memberships->renew($membership, $plan, $data);

        return response()->json($renewed->load('member', 'plan'), 201);
    }

    public function freeze(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('memberships.freeze');

        $data = $request->validate([
            'from' => ['required', 'date'],
            'until' => ['required', 'date', 'after:from'],
        ]);

        $frozen = $this->memberships->freeze(
            $membership,
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['until']),
        );

        return response()->json($frozen->fresh());
    }

    public function unfreeze(Membership $membership): JsonResponse
    {
        $this->authorize('memberships.freeze');

        return response()->json($this->memberships->unfreeze($membership)->fresh());
    }

    public function cancel(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('memberships.delete');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->memberships->cancel($membership, $data['reason'] ?? null)->fresh());
    }

    /** Manual adjustment of the session quota, for a make-good or a mistake. */
    public function adjustSessions(Request $request, Membership $membership): JsonResponse
    {
        $this->authorize('memberships.update');

        $data = $request->validate([
            'sessions' => ['required', 'integer', 'between:-100,100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        abort_if($membership->remaining_sessions === null, 422, __('general.not_session_based'));

        $membership->update([
            'remaining_sessions' => max(0, $membership->remaining_sessions + $data['sessions']),
            'notes' => trim($membership->notes."\n".($data['reason'] ?? '')),
        ]);

        return response()->json($membership->fresh());
    }

    /** The club's price list. */
    public function plans(Request $request): JsonResponse
    {
        $this->authorize('memberships.view');

        return response()->json(
            MembershipPlan::when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function storePlan(Request $request): JsonResponse
    {
        $this->authorize('memberships.create');

        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'array'],
            'type' => ['required', Rule::in(['duration', 'session', 'unlimited'])],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'session_count' => ['nullable', 'integer', 'min:1'],
            'price' => ['required', 'numeric', 'min:0'],
            'freeze_days' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'max:9'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        // A session plan without a count would never expire on sessions.
        if ($data['type'] === 'session' && empty($data['session_count'])) {
            abort(422, __('general.session_count_required'));
        }

        return response()->json(MembershipPlan::create($data), 201);
    }

    public function updatePlan(Request $request, MembershipPlan $plan): JsonResponse
    {
        $this->authorize('memberships.update');

        $plan->update($request->validate([
            'name' => ['sometimes', 'array'],
            'description' => ['nullable', 'array'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'session_count' => ['nullable', 'integer', 'min:1'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'freeze_days' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'max:9'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]));

        return response()->json($plan->fresh());
    }
}
