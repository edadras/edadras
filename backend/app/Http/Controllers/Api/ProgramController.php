<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BodyMeasurement;
use App\Models\Member;
use App\Models\NutritionPlan;
use App\Models\NutritionPlanMeal;
use App\Models\WorkoutPlan;
use App\Services\Ai\AiProgramGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Workout programs, meal plans and periodic body measurements. */
class ProgramController extends Controller
{
    public function __construct(private readonly AiProgramGenerator $generator) {}

    public function workoutPlans(Request $request): JsonResponse
    {
        $this->authorize('workouts.view');

        return response()->json(
            WorkoutPlan::query()
                ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
                ->when($request->query('coach_id'), fn ($q, $id) => $q->where('coach_id', $id))
                ->with('member:id,first_name,last_name', 'coach:id,first_name,last_name')
                ->latest()
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function showWorkoutPlan(WorkoutPlan $plan): JsonResponse
    {
        $this->authorize('workouts.view');

        return response()->json($plan->load('days.exercises', 'member:id,first_name,last_name'));
    }

    /**
     * Creates a program by hand. The whole structure arrives in one payload
     * so the coach's screen can save a full week in a single tap.
     */
    public function storeWorkoutPlan(Request $request): JsonResponse
    {
        $this->authorize('workouts.create');

        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'],
            'coach_id' => ['nullable', 'exists:coaches,id'],
            'title' => ['required', 'string', 'max:150'],
            'goal' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'days' => ['required', 'array', 'min:1'],
            'days.*.day_number' => ['required', 'integer', 'between:1,7'],
            'days.*.title' => ['nullable', 'string', 'max:120'],
            'days.*.notes' => ['nullable', 'string', 'max:1000'],
            'days.*.exercises' => ['required', 'array', 'min:1'],
            'days.*.exercises.*.name' => ['required', 'string', 'max:150'],
            'days.*.exercises.*.exercise_id' => ['nullable', 'exists:exercises,id'],
            'days.*.exercises.*.sets' => ['required', 'integer', 'between:1,20'],
            'days.*.exercises.*.reps' => ['required', 'string', 'max:20'],
            'days.*.exercises.*.weight' => ['nullable', 'numeric', 'min:0'],
            'days.*.exercises.*.rest_seconds' => ['nullable', 'integer', 'between:0,600'],
            'days.*.exercises.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $plan = DB::transaction(function () use ($data) {
            $plan = WorkoutPlan::create(collect($data)->except('days')->all());

            foreach ($data['days'] as $day) {
                $planDay = $plan->days()->create(collect($day)->except('exercises')->all());

                foreach ($day['exercises'] as $order => $exercise) {
                    $planDay->exercises()->create($exercise + ['sort_order' => $order]);
                }
            }

            return $plan;
        });

        return response()->json($plan->load('days.exercises'), 201);
    }

    /** Asks the AI module for a program tailored to the member. */
    public function generateWorkoutPlan(Request $request, Member $member): JsonResponse
    {
        $this->authorize('workouts.create');

        $options = $request->validate([
            'goal' => ['nullable', Rule::in(['fat_loss', 'muscle_gain', 'endurance', 'general_fitness', 'strength'])],
            'days_per_week' => ['nullable', 'integer', 'between:1,7'],
            'level' => ['nullable', Rule::in(['beginner', 'intermediate', 'advanced'])],
            'equipment' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'coach_id' => ['nullable', 'exists:coaches,id'],
        ]);

        return response()->json($this->generator->workoutPlan($member, $options), 201);
    }

    public function deleteWorkoutPlan(WorkoutPlan $plan): JsonResponse
    {
        $this->authorize('workouts.delete');

        $plan->delete();

        return response()->json(['message' => __('general.deleted')]);
    }

    public function nutritionPlans(Request $request): JsonResponse
    {
        $this->authorize('nutrition.view');

        return response()->json(
            NutritionPlan::query()
                ->when($request->query('member_id'), fn ($q, $id) => $q->where('member_id', $id))
                ->with('member:id,first_name,last_name')
                ->latest()
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function showNutritionPlan(NutritionPlan $plan): JsonResponse
    {
        $this->authorize('nutrition.view');

        return response()->json($plan->load('meals', 'member:id,first_name,last_name'));
    }

    public function storeNutritionPlan(Request $request): JsonResponse
    {
        $this->authorize('nutrition.create');

        $data = $request->validate([
            'member_id' => ['required', 'exists:members,id'],
            'coach_id' => ['nullable', 'exists:coaches,id'],
            'title' => ['required', 'string', 'max:150'],
            'daily_calories' => ['nullable', 'integer', 'between:500,8000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'meals' => ['required', 'array', 'min:1'],
            'meals.*.meal_type' => ['required', Rule::in(NutritionPlanMeal::TYPES)],
            'meals.*.title' => ['required', 'string', 'max:150'],
            'meals.*.description' => ['nullable', 'string', 'max:1000'],
            'meals.*.calories' => ['nullable', 'integer', 'min:0'],
            'meals.*.protein' => ['nullable', 'numeric', 'min:0'],
            'meals.*.carbs' => ['nullable', 'numeric', 'min:0'],
            'meals.*.fat' => ['nullable', 'numeric', 'min:0'],
            'meals.*.time' => ['nullable', 'string', 'max:10'],
        ]);

        $plan = DB::transaction(function () use ($data) {
            $plan = NutritionPlan::create(collect($data)->except('meals')->all());

            foreach ($data['meals'] as $order => $meal) {
                $plan->meals()->create($meal + ['sort_order' => $order]);
            }

            return $plan;
        });

        return response()->json($plan->load('meals'), 201);
    }

    public function generateNutritionPlan(Request $request, Member $member): JsonResponse
    {
        $this->authorize('nutrition.create');

        $options = $request->validate([
            'goal' => ['nullable', Rule::in(['fat_loss', 'muscle_gain', 'endurance', 'general_fitness', 'strength'])],
            'calories' => ['nullable', 'integer', 'between:800,6000'],
            'restrictions' => ['nullable', 'string', 'max:500'],
            'coach_id' => ['nullable', 'exists:coaches,id'],
        ]);

        return response()->json($this->generator->nutritionPlan($member, $options), 201);
    }

    /** Body metrics over time, with the chart series the app plots. */
    public function measurements(Request $request, Member $member): JsonResponse
    {
        $this->authorize('measurements.view');

        $measurements = $member->measurements()
            ->orderBy('measured_at')
            ->get();

        return response()->json([
            'data' => $measurements,
            'series' => [
                'weight' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->weight])->all(),
                'bmi' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->bmi])->all(),
                'fat_percent' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->fat_percent])->all(),
                'muscle_mass' => $measurements->map(fn ($m) => ['date' => $m->measured_at->toDateString(), 'value' => (float) $m->muscle_mass])->all(),
            ],
            'change' => $this->measurementChange($measurements),
        ]);
    }

    public function storeMeasurement(Request $request, Member $member): JsonResponse
    {
        $this->authorize('measurements.create');

        $data = $request->validate([
            'measured_at' => ['nullable', 'date'],
            'weight' => ['nullable', 'numeric', 'between:20,400'],
            'height' => ['nullable', 'integer', 'between:80,260'],
            'fat_percent' => ['nullable', 'numeric', 'between:1,70'],
            'muscle_mass' => ['nullable', 'numeric', 'between:1,150'],
            'arm' => ['nullable', 'numeric', 'between:10,100'],
            'chest' => ['nullable', 'numeric', 'between:40,200'],
            'waist' => ['nullable', 'numeric', 'between:40,200'],
            'hip' => ['nullable', 'numeric', 'between:40,200'],
            'thigh' => ['nullable', 'numeric', 'between:20,120'],
            'calf' => ['nullable', 'numeric', 'between:15,80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $measurement = $member->measurements()->create($data + [
            'measured_at' => $data['measured_at'] ?? today(),
            'height' => $data['height'] ?? $member->height,
            'recorded_by' => $request->user()->id,
        ]);

        // The member card should show the newest weight without a join.
        if (! empty($data['weight'])) {
            $member->update(['weight' => $data['weight']]);
        }

        return response()->json($measurement, 201);
    }

    public function deleteMeasurement(BodyMeasurement $measurement): JsonResponse
    {
        $this->authorize('measurements.delete');

        $measurement->delete();

        return response()->json(['message' => __('general.deleted')]);
    }

    /** @return array<string, float|null> */
    protected function measurementChange(Collection $measurements): array
    {
        $first = $measurements->first();
        $last = $measurements->last();

        if (! $first || $measurements->count() < 2) {
            return [];
        }

        return collect(['weight', 'bmi', 'fat_percent', 'muscle_mass', 'waist'])
            ->mapWithKeys(fn (string $field) => [
                $field => ($first->$field !== null && $last->$field !== null)
                    ? round((float) $last->$field - (float) $first->$field, 2)
                    : null,
            ])
            ->all();
    }
}
