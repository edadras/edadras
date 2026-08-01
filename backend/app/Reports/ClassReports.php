<?php

namespace App\Reports;

use App\Models\ClassBooking;
use App\Models\ClassSession;
use App\Models\GymClass;
use App\Reports\Concerns\AggregatesRows;

/** Which classes fill up, which run half empty and what they bring in. */
class ClassReports
{
    use AggregatesRows;

    public function roster(): array
    {
        return GymClass::with('coach:id,first_name,last_name')
            ->withCount('sessions')
            ->get()
            ->map(fn (GymClass $c) => [
                'name' => $c->name,
                'kind' => $c->kind,
                'coach' => $c->coach?->full_name,
                'capacity' => $c->capacity,
                'duration_minutes' => $c->duration_minutes,
                'price' => (float) $c->price,
                'gender' => $c->gender,
                'sessions' => $c->sessions_count,
                'active' => (bool) $c->is_active,
            ])
            ->all();
    }

    public function schedule($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->with('gymClass:id,name,kind', 'coach:id,first_name,last_name')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => $this->sessionRow($s))
            ->all();
    }

    public function occupancy($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->with('gymClass:id,name,kind')
            ->get()
            ->groupBy('gym_class_id')
            ->map(function ($sessions) {
                $seats = (int) $sessions->sum('capacity');
                $booked = (int) $sessions->sum('booked_count');

                return [
                    'class' => $sessions->first()->gymClass?->name ?? '—',
                    'kind' => $sessions->first()->gymClass?->kind,
                    'sessions' => $sessions->count(),
                    'seats' => $seats,
                    'booked' => $booked,
                    'occupancy' => $seats ? round($booked / $seats * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('occupancy')
            ->values()
            ->all();
    }

    public function fullSessions($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->whereColumn('booked_count', '>=', 'capacity')
            ->with('gymClass:id,name,kind', 'coach:id,first_name,last_name')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => $this->sessionRow($s))
            ->all();
    }

    /** Sessions that ran with nobody in them — the schedule's dead weight. */
    public function emptySessions($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->where('booked_count', 0)
            ->with('gymClass:id,name,kind', 'coach:id,first_name,last_name')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => $this->sessionRow($s))
            ->all();
    }

    public function byKind($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->with('gymClass:id,kind')
            ->get()
            ->groupBy(fn (ClassSession $s) => $s->gymClass?->kind ?? '—')
            ->map(fn ($rows, $kind) => [
                'label' => (string) $kind,
                'total' => $rows->count(),
                'booked' => (int) $rows->sum('booked_count'),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    public function poolSessions($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->whereHas('gymClass', fn ($q) => $q->where('kind', 'pool'))
            ->with('gymClass:id,name,kind', 'coach:id,first_name,last_name')
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => $this->sessionRow($s))
            ->all();
    }

    public function bookingsByDay(int $days): array
    {
        return $this->dailySeries(ClassBooking::query(), 'booked_at', $days);
    }

    public function bookingsByStatus($from, $to): array
    {
        return $this->breakdown(
            ClassBooking::whereBetween('booked_at', [$from, $to]),
            'status'
        );
    }

    public function cancelledBookings($from, $to): array
    {
        return ClassBooking::where('status', 'cancelled')
            ->whereBetween('cancelled_at', [$from, $to])
            ->with('member:id,code,first_name,last_name', 'session.gymClass:id,name')
            ->orderByDesc('cancelled_at')
            ->get()
            ->map(fn (ClassBooking $b) => [
                'code' => $b->member?->code,
                'member' => $b->member?->full_name,
                'class' => $b->session?->gymClass?->name,
                'session_at' => $b->session?->starts_at?->toDateTimeString(),
                'cancelled_at' => $b->cancelled_at?->toDateTimeString(),
            ])
            ->all();
    }

    public function bookingsByMember($from, $to, int $limit = 30): array
    {
        return ClassBooking::whereBetween('booked_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->with('member:id,code,first_name,last_name')
            ->get()
            ->groupBy('member_id')
            ->map(fn ($rows) => [
                'code' => $rows->first()->member?->code,
                'member' => $rows->first()->member?->full_name,
                'bookings' => $rows->count(),
                'attended' => $rows->where('status', 'attended')->count(),
            ])
            ->sortByDesc('bookings')
            ->take($limit)
            ->values()
            ->all();
    }

    public function revenue($from, $to): array
    {
        return ClassBooking::whereBetween('booked_at', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->with('session.gymClass:id,name')
            ->get()
            ->groupBy(fn (ClassBooking $b) => $b->session?->gymClass?->name ?? '—')
            ->map(fn ($rows, $class) => [
                'class' => (string) $class,
                'bookings' => $rows->count(),
                'revenue' => round((float) $rows->sum('price'), 2),
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /** Booked against actually turned up, per class. */
    public function attendanceVsBookings($from, $to): array
    {
        return ClassBooking::whereBetween('booked_at', [$from, $to])
            ->with('session.gymClass:id,name')
            ->get()
            ->groupBy(fn (ClassBooking $b) => $b->session?->gymClass?->name ?? '—')
            ->map(function ($rows, $class) {
                $booked = $rows->where('status', '!=', 'cancelled')->count();
                $attended = $rows->where('status', 'attended')->count();

                return [
                    'class' => (string) $class,
                    'booked' => $booked,
                    'attended' => $attended,
                    'cancelled' => $rows->where('status', 'cancelled')->count(),
                    'show_rate' => $booked ? round($attended / $booked * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('booked')
            ->values()
            ->all();
    }

    protected function sessionRow(ClassSession $s): array
    {
        return [
            'class' => $s->gymClass?->name,
            'kind' => $s->gymClass?->kind,
            'coach' => $s->coach?->full_name,
            'starts_at' => $s->starts_at?->toDateTimeString(),
            'capacity' => $s->capacity,
            'booked' => $s->booked_count,
            'free' => max(0, (int) $s->capacity - (int) $s->booked_count),
            'status' => $s->status,
            'price' => (float) $s->price,
        ];
    }
}
