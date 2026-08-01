<?php

namespace App\Reports;

use App\Models\ClassSession;
use App\Models\Coach;
use App\Models\NutritionPlan;
use App\Models\Transaction;
use App\Models\WorkoutPlan;
use App\Reports\Concerns\AggregatesRows;

/** What the coaching staff costs and what it delivers. */
class CoachReports
{
    use AggregatesRows;

    public function roster(): array
    {
        return Coach::withCount('classes')
            ->get()
            ->map(fn (Coach $c) => [
                'name' => $c->full_name,
                'phone' => $c->phone,
                'specialties' => $c->specialties,
                'contract_type' => $c->contract_type,
                'salary_amount' => (float) $c->salary_amount,
                'commission_percent' => (float) $c->commission_percent,
                'hired_at' => $c->hired_at?->toDateString(),
                'status' => $c->status,
                'classes' => $c->classes_count,
            ])
            ->all();
    }

    public function performance($from, $to): array
    {
        return ClassSession::whereBetween('starts_at', [$from, $to])
            ->with('coach:id,first_name,last_name')
            ->get()
            ->groupBy('coach_id')
            ->map(function ($sessions) {
                $seats = (int) $sessions->sum('capacity');
                $booked = (int) $sessions->sum('booked_count');

                return [
                    'coach' => $sessions->first()->coach?->full_name ?? '—',
                    'sessions' => $sessions->count(),
                    'bookings' => $booked,
                    'seats' => $seats,
                    'occupancy' => $seats ? round($booked / $seats * 100, 1) : 0.0,
                    'revenue' => round((float) $sessions->sum(fn (ClassSession $s) => (float) $s->price * $s->booked_count), 2),
                ];
            })
            ->sortByDesc('bookings')
            ->values()
            ->all();
    }

    public function sessionsByMonth(int $months = 12): array
    {
        return $this->monthlySeries(ClassSession::query(), 'starts_at', $months);
    }

    /** The salary and commission line, straight out of the cash book. */
    public function salaryCost($from, $to): array
    {
        return Transaction::expense()
            ->where('category', 'salary')
            ->between($from, $to)
            ->get()
            ->groupBy('reference_id')
            ->map(fn ($rows) => [
                'coach' => Coach::withTrashed()->find($rows->first()->reference_id)?->full_name ?? '—',
                'payments' => $rows->count(),
                'total' => round((float) $rows->sum('amount'), 2),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /** Members this coach is actually responsible for, by written programme. */
    public function studentsPerCoach(): array
    {
        $workouts = WorkoutPlan::where('status', 'active')->get()->groupBy('coach_id');
        $nutrition = NutritionPlan::where('status', 'active')->get()->groupBy('coach_id');

        return Coach::all()
            ->map(function (Coach $coach) use ($workouts, $nutrition) {
                $members = collect()
                    ->merge($workouts->get($coach->id, collect())->pluck('member_id'))
                    ->merge($nutrition->get($coach->id, collect())->pluck('member_id'))
                    ->unique();

                return [
                    'coach' => $coach->full_name,
                    'students' => $members->count(),
                    'workout_plans' => $workouts->get($coach->id, collect())->count(),
                    'nutrition_plans' => $nutrition->get($coach->id, collect())->count(),
                ];
            })
            ->sortByDesc('students')
            ->values()
            ->all();
    }

    public function plansWritten($from, $to): array
    {
        $workouts = WorkoutPlan::whereBetween('created_at', [$from, $to])->get()->groupBy('coach_id');
        $nutrition = NutritionPlan::whereBetween('created_at', [$from, $to])->get()->groupBy('coach_id');

        return Coach::all()
            ->map(fn (Coach $coach) => [
                'coach' => $coach->full_name,
                'workout_plans' => $workouts->get($coach->id, collect())->count(),
                'nutrition_plans' => $nutrition->get($coach->id, collect())->count(),
                'ai_generated' => $workouts->get($coach->id, collect())->where('generated_by_ai', true)->count()
                    + $nutrition->get($coach->id, collect())->where('generated_by_ai', true)->count(),
            ])
            ->sortByDesc(fn (array $row) => $row['workout_plans'] + $row['nutrition_plans'])
            ->values()
            ->all();
    }

    public function byContractType(): array
    {
        return $this->breakdown(Coach::query(), 'contract_type');
    }

    public function byStatus(): array
    {
        return $this->breakdown(Coach::query(), 'status');
    }
}
