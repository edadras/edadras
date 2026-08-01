<?php

namespace App\Services;

use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\GymClass;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Class and pool reservations. Capacity is enforced under a row lock so two
 * phones tapping "book" at the same second cannot oversell the last seat.
 */
class BookingService
{
    public function book(ClassSession $session, Member $member): ClassBooking
    {
        return DB::transaction(function () use ($session, $member) {
            $session = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            $existing = ClassBooking::where('class_session_id', $session->id)
                ->where('member_id', $member->id)
                ->first();

            if ($existing && $existing->status !== 'cancelled') {
                throw new RuntimeException('already_booked');
            }

            if ($session->status !== 'scheduled') {
                throw new RuntimeException('session_not_bookable');
            }

            if ($session->starts_at->isPast()) {
                throw new RuntimeException('session_already_started');
            }

            if ($session->remainingSeats() < 1) {
                throw new RuntimeException('session_full');
            }

            $booking = $existing
                ? tap($existing)->update(['status' => 'booked', 'cancelled_at' => null, 'booked_at' => now()])
                : ClassBooking::create([
                    'class_session_id' => $session->id,
                    'member_id' => $member->id,
                    'status' => 'booked',
                    'price' => $session->price,
                    'booked_at' => now(),
                ]);

            $session->increment('booked_count');

            return $booking;
        });
    }

    public function cancel(ClassBooking $booking): ClassBooking
    {
        return DB::transaction(function () use ($booking) {
            if ($booking->status === 'cancelled') {
                return $booking;
            }

            $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            ClassSession::whereKey($booking->class_session_id)
                ->where('booked_count', '>', 0)
                ->decrement('booked_count');

            return $booking;
        });
    }

    public function markAttendance(ClassBooking $booking, bool $attended): ClassBooking
    {
        $booking->update(['status' => $attended ? 'attended' : 'no_show']);

        return $booking;
    }

    /**
     * Generates the concrete dated sessions of a class over a period.
     *
     * @param  array<int, int>  $weekdays  0 = Sunday ... 6 = Saturday
     */
    public function schedule(int $gymClassId, array $weekdays, string $time, string $from, string $to, ?int $capacity = null): int
    {
        $class = GymClass::findOrFail($gymClassId);
        $cursor = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $created = 0;

        while ($cursor->lessThanOrEqualTo($end)) {
            if (in_array($cursor->dayOfWeek, $weekdays, true)) {
                $startsAt = $cursor->setTimeFromTimeString($time);

                $exists = ClassSession::where('gym_class_id', $class->id)
                    ->where('starts_at', $startsAt)
                    ->exists();

                if (! $exists) {
                    ClassSession::create([
                        'gym_class_id' => $class->id,
                        'coach_id' => $class->coach_id,
                        'starts_at' => $startsAt,
                        'ends_at' => $startsAt->addMinutes($class->duration_minutes),
                        'capacity' => $capacity ?? $class->capacity,
                        'price' => $class->price,
                        'status' => 'scheduled',
                    ]);
                    $created++;
                }
            }

            $cursor = $cursor->addDay();
        }

        return $created;
    }
}
