<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Services\CheckInService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reception desk. The manager app scans a QR badge or taps an NFC card
 * here, and the response is what the scanner screen shows.
 */
class AttendanceController extends Controller
{
    public function __construct(private readonly CheckInService $checkIn) {}

    /** Dry run: shows the green or red card before the entry is written. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('attendance.checkin');

        $member = $this->resolveMember($request);

        $preview = $this->checkIn->preview($member);

        return response()->json([
            'allowed' => $preview['allowed'],
            'reason' => $preview['reason'],
            'message' => $preview['reason'] ? __('checkin.'.$preview['reason']) : __('checkin.allowed'),
            'member' => $preview['member']->only(['id', 'code', 'first_name', 'last_name', 'photo_path', 'status']),
            'membership' => $preview['membership'],
            'remaining_sessions' => $preview['remaining_sessions'],
            'days_remaining' => $preview['days_remaining'],
        ]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $this->authorize('attendance.checkin');

        $member = $this->resolveMember($request);

        $attendance = $this->checkIn->checkIn(
            $member,
            $request->user()->id,
            $request->input('method', 'qr'),
            $request->input('device'),
        );

        $attendance->load('membership');

        return response()->json([
            'message' => __('checkin.allowed'),
            'attendance' => $attendance,
            'member' => $member->only(['id', 'code', 'first_name', 'last_name', 'photo_path']),
            'remaining_sessions' => $attendance->membership?->remaining_sessions,
            'days_remaining' => $attendance->membership?->daysRemaining(),
        ], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $this->authorize('attendance.checkout');

        $member = $this->resolveMember($request);

        $attendance = $this->checkIn->checkOut($member, $request->user()->id);

        return response()->json([
            'message' => __('checkin.checked_out'),
            'attendance' => $attendance,
            'duration_minutes' => $attendance->durationMinutes(),
        ]);
    }

    /** Attendance list, filtered by the period the app is showing. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('attendance.view');

        [$from, $to] = $this->period($request->query('period', 'today'), $request);

        $attendances = Attendance::query()
            ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
            ->whereBetween('checked_in_at', [$from, $to])
            ->with('member:id,code,first_name,last_name,photo_path', 'staff:id,name')
            ->latest('checked_in_at')
            ->paginate($request->integer('per_page', 50));

        return response()->json($attendances);
    }

    /** Who is inside the club right now. */
    public function inside(): JsonResponse
    {
        $this->authorize('attendance.view');

        return response()->json(
            Attendance::stillInside()
                ->with('member:id,code,first_name,last_name,photo_path')
                ->orderBy('checked_in_at')
                ->get()
        );
    }

    /** Manual entry, for a member who forgot their badge. */
    public function storeManual(Request $request): JsonResponse
    {
        $this->authorize('attendance.checkin');

        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $attendance = $this->checkIn->checkIn(
            Member::findOrFail($data['member_id']),
            $request->user()->id,
            'manual',
            $request->input('device'),
        );

        if (! empty($data['notes'])) {
            $attendance->update(['notes' => $data['notes']]);
        }

        return response()->json($attendance->fresh(), 201);
    }

    protected function resolveMember(Request $request): Member
    {
        $request->validate([
            'qr_token' => ['required_without_all:nfc_uid,member_id', 'string'],
            'nfc_uid' => ['required_without_all:qr_token,member_id', 'string'],
            'member_id' => ['required_without_all:qr_token,nfc_uid', 'integer'],
            'method' => ['nullable', Rule::in(['qr', 'nfc', 'manual'])],
            'device' => ['nullable', 'string', 'max:120'],
        ]);

        return match (true) {
            $request->filled('qr_token') => $this->checkIn->findByQrToken($request->input('qr_token')),
            $request->filled('nfc_uid') => $this->checkIn->findByNfcUid($request->input('nfc_uid')),
            default => Member::findOrFail($request->integer('member_id')),
        };
    }

    /** @return array{0: CarbonInterface, 1: CarbonInterface} */
    protected function period(string $period, Request $request): array
    {
        return match ($period) {
            'yesterday' => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'week' => [today()->startOfWeek(), today()->endOfWeek()],
            'month' => [today()->startOfMonth(), today()->endOfMonth()],
            'range' => [
                Carbon::parse($request->query('from', today()->toDateString()))->startOfDay(),
                Carbon::parse($request->query('to', today()->toDateString()))->endOfDay(),
            ],
            default => [today()->startOfDay(), today()->endOfDay()],
        };
    }
}
