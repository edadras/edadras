<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\GymClass;
use App\Models\Member;
use App\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Classes, their dated sessions and the bookings against them. Pool "sans"
 * are classes with kind = pool, so capacity and reservation work the same.
 */
class ClassController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('classes.view');

        return response()->json(
            GymClass::query()
                ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
                ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
                ->with('coach:id,first_name,last_name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('classes.create');

        return response()->json(GymClass::create($this->validated($request)), 201);
    }

    public function update(Request $request, GymClass $class): JsonResponse
    {
        $this->authorize('classes.update');

        $class->update($this->validated($request, $class));

        return response()->json($class->fresh());
    }

    public function destroy(GymClass $class): JsonResponse
    {
        $this->authorize('classes.delete');

        $class->update(['is_active' => false]);

        return response()->json(['message' => __('general.deactivated')]);
    }

    /** The timetable. */
    public function sessions(Request $request): JsonResponse
    {
        $this->authorize('classes.view');

        $from = $request->date('from') ?? today()->startOfDay();
        $to = $request->date('to') ?? today()->addWeek()->endOfDay();

        return response()->json(
            ClassSession::query()
                ->whereBetween('starts_at', [$from, $to])
                ->when($request->query('gym_class_id'), fn ($q, $id) => $q->where('gym_class_id', $id))
                ->when($request->query('coach_id'), fn ($q, $id) => $q->where('coach_id', $id))
                ->with('gymClass:id,name,kind,color', 'coach:id,first_name,last_name')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (ClassSession $session) => array_merge($session->toArray(), [
                    'remaining_seats' => $session->remainingSeats(),
                    'is_bookable' => $session->isBookable(),
                ]))
        );
    }

    public function storeSession(Request $request): JsonResponse
    {
        $this->authorize('classes.create');

        $data = $request->validate([
            'gym_class_id' => ['required', 'exists:gym_classes,id'],
            'coach_id' => ['nullable', 'exists:coaches,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $class = GymClass::findOrFail($data['gym_class_id']);
        $startsAt = Carbon::parse($data['starts_at']);

        $session = ClassSession::create([
            'gym_class_id' => $class->id,
            'coach_id' => $data['coach_id'] ?? $class->coach_id,
            'starts_at' => $startsAt,
            'ends_at' => $data['ends_at'] ?? $startsAt->copy()->addMinutes($class->duration_minutes),
            'capacity' => $data['capacity'] ?? $class->capacity,
            'price' => $data['price'] ?? $class->price,
            'status' => 'scheduled',
        ]);

        return response()->json($session, 201);
    }

    /** Generates a recurring timetable in one call. */
    public function scheduleSessions(Request $request): JsonResponse
    {
        $this->authorize('classes.create');

        $data = $request->validate([
            'gym_class_id' => ['required', 'exists:gym_classes,id'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'time' => ['required', 'date_format:H:i'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $created = $this->bookings->schedule(
            $data['gym_class_id'],
            $data['weekdays'],
            $data['time'],
            $data['from'],
            $data['to'],
            $data['capacity'] ?? null,
        );

        return response()->json(['created' => $created], 201);
    }

    public function cancelSession(ClassSession $session): JsonResponse
    {
        $this->authorize('classes.update');

        $session->update(['status' => 'cancelled']);
        $session->bookings()->where('status', 'booked')->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        return response()->json($session->fresh());
    }

    public function book(Request $request, ClassSession $session): JsonResponse
    {
        $this->authorize('bookings.create');

        $data = $request->validate(['member_id' => ['required', 'exists:members,id']]);

        try {
            $booking = $this->bookings->book($session, Member::findOrFail($data['member_id']));
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => __('booking.'.$e->getMessage()),
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($booking->load('session.gymClass'), 201);
    }

    public function cancelBooking(ClassBooking $booking): JsonResponse
    {
        $this->authorize('bookings.update');

        return response()->json($this->bookings->cancel($booking)->fresh());
    }

    public function markAttendance(Request $request, ClassBooking $booking): JsonResponse
    {
        $this->authorize('bookings.update');

        $data = $request->validate(['attended' => ['required', 'boolean']]);

        return response()->json($this->bookings->markAttendance($booking, $data['attended'])->fresh());
    }

    public function sessionBookings(ClassSession $session): JsonResponse
    {
        $this->authorize('bookings.view');

        return response()->json(
            $session->bookings()
                ->with('member:id,code,first_name,last_name,photo_path')
                ->where('status', '!=', 'cancelled')
                ->get()
        );
    }

    protected function validated(Request $request, ?GymClass $class = null): array
    {
        return $request->validate([
            'coach_id' => ['nullable', 'exists:coaches,id'],
            'name' => [$class ? 'sometimes' : 'required', 'array'],
            'name.*' => ['string', 'max:120'],
            'description' => ['nullable', 'array'],
            'kind' => ['nullable', Rule::in(['class', 'pool', 'private'])],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'duration_minutes' => ['nullable', 'integer', 'between:10,480'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'mixed'])],
            'color' => ['nullable', 'string', 'max:9'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
