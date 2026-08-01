<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class MemberController extends Controller
{
    public function __construct(private readonly TenantContext $tenancy) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('members.view');

        $members = Member::query()
            ->search($request->query('q'))
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('gender'), fn ($q, $gender) => $q->where('gender', $gender))
            ->when($request->boolean('expiring'), fn ($q) => $q->whereHas('memberships', fn ($m) => $m->expiringWithin(7)))
            ->with('activeMembership.plan')
            ->orderBy($request->query('sort', 'created_at'), $request->query('direction', 'desc'))
            ->paginate($request->integer('per_page', 25));

        return response()->json($members);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('members.create');

        $data = $this->validated($request);

        $data['code'] = $data['code'] ?? $this->nextCode();

        $member = Member::create($data);

        return response()->json($member->fresh(), 201);
    }

    public function show(Member $member): JsonResponse
    {
        $this->authorize('members.view');

        return response()->json($member->load([
            'activeMembership.plan',
            'memberships.plan',
            'measurements' => fn ($q) => $q->latest('measured_at')->limit(12),
            'wallet',
        ]));
    }

    public function update(Request $request, Member $member): JsonResponse
    {
        $this->authorize('members.update');

        $member->update($this->validated($request, $member));

        return response()->json($member->fresh());
    }

    public function destroy(Member $member): JsonResponse
    {
        $this->authorize('members.delete');

        $member->delete();

        return response()->json(['message' => __('general.deleted')]);
    }

    /** The member's QR badge, as an SVG the app can render or print. */
    public function qrCode(Member $member): mixed
    {
        $this->authorize('members.view');

        $svg = QrCode::format('svg')
            ->size(320)
            ->margin(1)
            ->errorCorrection('M')
            ->generate($member->qr_token);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** Rotates the QR token, for a badge that was lost or shared. */
    public function regenerateQrCode(Member $member): JsonResponse
    {
        $this->authorize('members.update');

        $member->update(['qr_token' => strtolower(Str::random(40))]);

        return response()->json(['qr_token' => $member->qr_token]);
    }

    public function uploadPhoto(Request $request, Member $member): JsonResponse
    {
        $this->authorize('members.update');

        $request->validate([
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        if ($member->photo_path) {
            Storage::disk('public')->delete($member->photo_path);
        }

        $path = $request->file('photo')->store("tenants/{$member->tenant_id}/members", 'public');

        $member->update(['photo_path' => $path]);

        return response()->json([
            'photo_path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    /** Links an NFC card or wristband to the member. */
    public function linkNfc(Request $request, Member $member): JsonResponse
    {
        $this->authorize('members.update');

        $data = $request->validate([
            'nfc_uid' => ['required', 'string', 'max:64', Rule::unique('members', 'nfc_uid')->ignore($member->id)],
        ]);

        $member->update($data);

        return response()->json($member->fresh());
    }

    protected function validated(Request $request, ?Member $member = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:32', Rule::unique('members', 'code')
                ->where('tenant_id', $this->tenancy->id())
                ->ignore($member?->id)],
            'first_name' => [$member ? 'sometimes' : 'required', 'string', 'max:80'],
            'last_name' => [$member ? 'sometimes' : 'required', 'string', 'max:80'],
            'phone' => [$member ? 'sometimes' : 'required', 'string', 'max:32'],
            'national_id' => ['nullable', 'string', 'max:32'],
            'passport_no' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'height' => ['nullable', 'integer', 'between:80,260'],
            'weight' => ['nullable', 'numeric', 'between:20,400'],
            'blood_type' => ['nullable', 'string', 'max:5'],
            'diseases' => ['nullable', 'string', 'max:2000'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'emergency_name' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'blocked'])],
            'joined_at' => ['nullable', 'date'],
        ]);
    }

    /** Sequential member number inside the club: 1001, 1002, ... */
    protected function nextCode(): string
    {
        $last = Member::withTrashed()->orderByDesc('id')->value('code');

        return (string) (((int) preg_replace('/\D/', '', (string) $last) ?: 1000) + 1);
    }
}
