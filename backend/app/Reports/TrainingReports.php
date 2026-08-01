<?php

namespace App\Reports;

use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Member;
use App\Models\NutritionPlan;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanExercise;
use App\Reports\Concerns\AggregatesRows;
use Illuminate\Support\Facades\DB;

/** Programmes written, bodies measured, and whether any of it is working. */
class TrainingReports
{
    use AggregatesRows;

    public function activeWorkoutPlans(): array
    {
        return WorkoutPlan::where('status', 'active')
            ->with('member:id,code,first_name,last_name', 'coach:id,first_name,last_name')
            ->withCount('days')
            ->get()
            ->map(fn (WorkoutPlan $p) => [
                'member' => $p->member?->full_name,
                'coach' => $p->coach?->full_name,
                'title' => $p->title,
                'goal' => $p->goal,
                'days' => $p->days_count,
                'starts_at' => $p->starts_at?->toDateString(),
                'ends_at' => $p->ends_at?->toDateString(),
                'ai' => (bool) $p->generated_by_ai,
            ])
            ->all();
    }

    public function activeNutritionPlans(): array
    {
        return NutritionPlan::where('status', 'active')
            ->with('member:id,code,first_name,last_name', 'coach:id,first_name,last_name')
            ->withCount('meals')
            ->get()
            ->map(fn (NutritionPlan $p) => [
                'member' => $p->member?->full_name,
                'coach' => $p->coach?->full_name,
                'title' => $p->title,
                'daily_calories' => $p->daily_calories,
                'meals' => $p->meals_count,
                'starts_at' => $p->starts_at?->toDateString(),
                'ai' => (bool) $p->generated_by_ai,
            ])
            ->all();
    }

    public function workoutPlansByStatus(): array
    {
        return $this->breakdown(WorkoutPlan::query(), 'status');
    }

    public function nutritionPlansByStatus(): array
    {
        return $this->breakdown(NutritionPlan::query(), 'status');
    }

    /** How much of the coaching load the AI module is actually carrying. */
    public function aiShare(): array
    {
        $workouts = WorkoutPlan::get(['generated_by_ai']);
        $nutrition = NutritionPlan::get(['generated_by_ai']);

        return [
            'workout_plans' => $workouts->count(),
            'workout_by_ai' => $workouts->where('generated_by_ai', true)->count(),
            'nutrition_plans' => $nutrition->count(),
            'nutrition_by_ai' => $nutrition->where('generated_by_ai', true)->count(),
        ];
    }

    public function membersWithoutProgram(): array
    {
        return Member::active()
            ->whereDoesntHave('workoutPlans', fn ($q) => $q->where('status', 'active'))
            ->get()
            ->map(fn (Member $m) => [
                'code' => $m->code,
                'name' => $m->full_name,
                'phone' => $m->phone,
                'joined_at' => $m->joined_at?->toDateString(),
            ])
            ->all();
    }

    public function measurementsLog($from, $to): array
    {
        return BodyMeasurement::whereBetween('measured_at', [$from, $to])
            ->with('member:id,code,first_name,last_name')
            ->orderByDesc('measured_at')
            ->get()
            ->map(fn (BodyMeasurement $m) => [
                'date' => $m->measured_at?->toDateString(),
                'code' => $m->member?->code,
                'member' => $m->member?->full_name,
                'weight' => (float) $m->weight,
                'bmi' => (float) $m->bmi,
                'fat_percent' => (float) $m->fat_percent,
                'muscle_mass' => (float) $m->muscle_mass,
                'waist' => (float) $m->waist,
            ])
            ->all();
    }

    public function weightChange(int $limit = 30): array
    {
        return $this->change('weight', $limit);
    }

    public function bodyFatChange(int $limit = 30): array
    {
        return $this->change('fat_percent', $limit);
    }

    public function muscleChange(int $limit = 30): array
    {
        return $this->change('muscle_mass', $limit);
    }

    public function bmiDistribution(): array
    {
        return BodyMeasurement::whereNotNull('bmi')
            ->orderByDesc('measured_at')
            ->get()
            ->unique('member_id')
            ->groupBy(function (BodyMeasurement $m) {
                $bmi = (float) $m->bmi;

                return match (true) {
                    $bmi < 18.5 => 'underweight',
                    $bmi < 25 => 'healthy',
                    $bmi < 30 => 'overweight',
                    default => 'obese',
                };
            })
            ->map(fn ($rows, $band) => ['label' => (string) $band, 'total' => $rows->count()])
            ->values()
            ->all();
    }

    /** Which exercises the club's programmes actually lean on. */
    public function exerciseUsage(int $limit = 40): array
    {
        $used = WorkoutPlanExercise::whereNotNull('exercise_id')
            ->select('exercise_id', DB::raw('count(*) as report_total'))
            ->groupBy('exercise_id')
            ->pluck('report_total', 'exercise_id');

        return Exercise::whereIn('id', $used->keys())
            ->get()
            ->map(fn (Exercise $e) => [
                'name' => $e->name,
                'muscle_group' => $e->muscle_group,
                'equipment' => $e->equipment,
                'used' => (int) $used[$e->id],
            ])
            ->sortByDesc('used')
            ->take($limit)
            ->values()
            ->all();
    }

    public function exercisesByMuscleGroup(): array
    {
        return $this->breakdown(Exercise::query(), 'muscle_group');
    }

    /**
     * First reading against the latest one, for every member with at least
     * two. Sorted so the biggest movers are at the top, either direction.
     */
    protected function change(string $column, int $limit): array
    {
        return BodyMeasurement::whereNotNull($column)
            ->with('member:id,code,first_name,last_name')
            ->orderBy('measured_at')
            ->get()
            ->groupBy('member_id')
            ->filter(fn ($rows) => $rows->count() >= 2)
            ->map(function ($rows) use ($column) {
                $first = (float) $rows->first()->{$column};
                $last = (float) $rows->last()->{$column};

                return [
                    'code' => $rows->first()->member?->code,
                    'member' => $rows->first()->member?->full_name,
                    'readings' => $rows->count(),
                    'from' => round($first, 2),
                    'to' => round($last, 2),
                    'change' => round($last - $first, 2),
                    'days' => (int) $rows->first()->measured_at->diffInDays($rows->last()->measured_at),
                ];
            })
            ->sortByDesc(fn (array $row) => abs($row['change']))
            ->take($limit)
            ->values()
            ->all();
    }
}
