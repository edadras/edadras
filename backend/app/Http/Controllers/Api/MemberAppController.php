<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\Member;
use App\Services\BookingService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Everything the member's own app talks to. Each endpoint works only on the
 * signed in member's data, never on anyone else's.
 */
class MemberAppController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly WalletService $wallets,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $membership = $member->activeMembership;

        return response()->json([
            'member' => $member->only(['id', 'code', 'first_name', 'last_name', 'photo_path', 'phone']),
            'club' => $member->tenant->only(['id', 'name', 'slug', 'logo_path', 'brand_color', 'phone', 'address', 'socials', 'working_hours', 'rules']),
            'membership' => $membership,
            'remaining_sessions' => $membership?->remaining_sessions,
            'days_remaining' => $membership?->daysRemaining(),
            'expires_at' => $membership?->ends_at?->toDateString(),
            'wallet_balance' => (float) ($member->wallet?->balance ?? 0),
            'qr_token' => $member->qr_token,
            'visits_this_month' => $member->attendances()->where('checked_in_at', '>=', today()->startOfMonth())->count(),
            'upcoming_bookings' => $member->bookings()
                ->where('status', 'booked')
                ->whereHas('session', fn ($q) => $q->where('starts_at', '>=', now()))
                ->with('session.gymClass:id,name,kind,color')
                ->get(),
            'active_workout_plan' => $member->workoutPlans()->where('status', 'active')->latest()->first()?->load('days.exercises'),
            'active_nutrition_plan' => $member->nutritionPlans()->where('status', 'active')->latest()->first()?->load('meals'),
        ]);
    }

    /** The badge the member shows at the door. */
    public function qrCode(Request $request): mixed
    {
        $member = $this->member($request);

        $svg = QrCode::format('svg')->size(320)->margin(1)->generate($member->qr_token);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function attendanceHistory(Request $request): JsonResponse
    {
        return response()->json(
            $this->member($request)
                ->attendances()
                ->latest('checked_in_at')
                ->paginate($request->integer('per_page', 30))
        );
    }

    public function payments(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return response()->json([
            'invoices' => $member->invoices()->with('items')->latest('issued_at')->limit(50)->get(),
            'payments' => $member->payments()->latest('paid_at')->limit(50)->get(),
        ]);
    }

    public function wallet(Request $request): JsonResponse
    {
        $member = $this->member($request);
        $wallet = $this->wallets->wallet($member);

        return response()->json([
            'balance' => (float) $wallet->balance,
            'transactions' => $wallet->transactions()->limit(50)->get(),
        ]);
    }

    /** The timetable, with what this member can still book. */
    public function schedule(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $from = $request->date('from') ?? now();
        $to = $request->date('to') ?? now()->addDays(14);

        $booked = $member->bookings()
            ->where('status', 'booked')
            ->pluck('class_session_id')
            ->all();

        $sessions = ClassSession::query()
            ->whereBetween('starts_at', [$from, $to])
            ->where('status', 'scheduled')
            ->when($request->query('kind'), fn ($q, $kind) => $q->whereHas('gymClass', fn ($c) => $c->where('kind', $kind)))
            ->with('gymClass:id,name,kind,color,gender', 'coach:id,first_name,last_name')
            ->orderBy('starts_at')
            ->get();

        return response()->json(
            $sessions->map(fn (ClassSession $session) => array_merge($session->toArray(), [
                'remaining_seats' => $session->remainingSeats(),
                'is_bookable' => $session->isBookable(),
                'is_booked' => in_array($session->id, $booked, true),
            ]))
        );
    }

    public function book(Request $request, ClassSession $session): JsonResponse
    {
        $member = $this->member($request);

        try {
            $booking = $this->bookings->book($session, $member);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => __('booking.'.$e->getMessage()),
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($booking->load('session.gymClass'), 201);
    }

    public function cancelBooking(Request $request, ClassBooking $booking): JsonResponse
    {
        abort_if($booking->member_id !== $this->member($request)->id, 403);

        return response()->json($this->bookings->cancel($booking)->fresh());
    }

    public function bookings(Request $request): JsonResponse
    {
        return response()->json(
            $this->member($request)
                ->bookings()
                ->with('session.gymClass:id,name,kind,color')
                ->latest('booked_at')
                ->paginate($request->integer('per_page', 30))
        );
    }

    public function programs(Request $request): JsonResponse
    {
        $member = $this->member($request);

        return response()->json([
            'workout_plans' => $member->workoutPlans()->with('days.exercises')->latest()->get(),
            'nutrition_plans' => $member->nutritionPlans()->with('meals')->latest()->get(),
        ]);
    }

    public function measurements(Request $request): JsonResponse
    {
        $measurements = $this->member($request)->measurements()->orderBy('measured_at')->get();

        return response()->json([
            'data' => $measurements,
            'series' => [
                'weight' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->weight]),
                'bmi' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->bmi]),
                'fat_percent' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->fat_percent]),
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:190'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'height' => ['nullable', 'integer', 'between:80,260'],
            'weight' => ['nullable', 'numeric', 'between:20,400'],
            'blood_type' => ['nullable', 'string', 'max:5'],
            'diseases' => ['nullable', 'string', 'max:2000'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'emergency_name' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:32'],
        ]);

        $member->update($data);

        if (isset($data['first_name']) || isset($data['last_name'])) {
            $request->user()->update(['name' => $member->fresh()->full_name]);
        }

        return response()->json($member->fresh());
    }

    protected function member(Request $request): Member
    {
        $member = $request->user()->member;

        abort_unless($member, 403, __('auth.not_a_member'));

        return $member;
    }
}
