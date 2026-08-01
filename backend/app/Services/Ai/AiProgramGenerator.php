<?php

namespace App\Services\Ai;

use App\Models\Member;
use App\Models\NutritionPlan;
use App\Models\WorkoutPlan;
use Illuminate\Support\Facades\DB;

/**
 * Builds workout and nutrition programs for a member. When the AI module is
 * configured Claude writes the program; otherwise a rule based template is
 * used, so the feature never leaves a coach empty handed.
 */
class AiProgramGenerator
{
    public function __construct(private readonly ClaudeClient $claude) {}

    /**
     * @param  array{goal?:string, days_per_week?:int, level?:string, equipment?:string, notes?:string}  $options
     */
    public function workoutPlan(Member $member, array $options = []): WorkoutPlan
    {
        $goal = $options['goal'] ?? 'general_fitness';
        $days = (int) ($options['days_per_week'] ?? 4);
        $level = $options['level'] ?? 'beginner';

        $generated = $this->claude->isConfigured()
            ? $this->claude->tryStructured(
                $this->workoutSystemPrompt(),
                $this->memberBrief($member)."\n".
                    "Goal: {$goal}. Training days per week: {$days}. Level: {$level}. ".
                    'Equipment: '.($options['equipment'] ?? 'full gym').'. '.
                    'Extra notes: '.($options['notes'] ?? 'none').'.',
                $this->workoutSchema(),
            )
            : null;

        $generated ??= $this->workoutTemplate($goal, $days, $level);

        return DB::transaction(function () use ($member, $generated, $goal, $options) {
            $plan = WorkoutPlan::create([
                'member_id' => $member->id,
                'coach_id' => $options['coach_id'] ?? null,
                'title' => $generated['title'] ?? __('ai.default_workout_title'),
                'notes' => $generated['notes'] ?? null,
                'starts_at' => today(),
                'ends_at' => today()->addWeeks(8),
                'goal' => $goal,
                'generated_by_ai' => true,
                'status' => 'active',
            ]);

            foreach ($generated['days'] ?? [] as $index => $day) {
                $planDay = $plan->days()->create([
                    'day_number' => $day['day_number'] ?? $index + 1,
                    'title' => $day['title'] ?? null,
                    'notes' => $day['notes'] ?? null,
                ]);

                foreach ($day['exercises'] ?? [] as $order => $exercise) {
                    $planDay->exercises()->create([
                        'name' => $exercise['name'],
                        'sets' => $exercise['sets'] ?? 3,
                        'reps' => (string) ($exercise['reps'] ?? '12'),
                        'rest_seconds' => $exercise['rest_seconds'] ?? 60,
                        'sort_order' => $order,
                        'notes' => $exercise['notes'] ?? null,
                    ]);
                }
            }

            return $plan->load('days.exercises');
        });
    }

    /**
     * @param  array{goal?:string, calories?:int, restrictions?:string, coach_id?:int}  $options
     */
    public function nutritionPlan(Member $member, array $options = []): NutritionPlan
    {
        $goal = $options['goal'] ?? 'general_fitness';
        $calories = $options['calories'] ?? $this->estimateCalories($member, $goal);

        $generated = $this->claude->isConfigured()
            ? $this->claude->tryStructured(
                $this->nutritionSystemPrompt(),
                $this->memberBrief($member)."\n".
                    "Goal: {$goal}. Daily calorie target: {$calories}. ".
                    'Restrictions: '.($options['restrictions'] ?? 'none').'.',
                $this->nutritionSchema(),
            )
            : null;

        $generated ??= $this->nutritionTemplate($calories);

        return DB::transaction(function () use ($member, $generated, $calories, $options) {
            $plan = NutritionPlan::create([
                'member_id' => $member->id,
                'coach_id' => $options['coach_id'] ?? null,
                'title' => $generated['title'] ?? __('ai.default_nutrition_title'),
                'daily_calories' => $generated['daily_calories'] ?? $calories,
                'notes' => $generated['notes'] ?? null,
                'starts_at' => today(),
                'ends_at' => today()->addWeeks(4),
                'generated_by_ai' => true,
                'status' => 'active',
            ]);

            foreach ($generated['meals'] ?? [] as $order => $meal) {
                $plan->meals()->create([
                    'meal_type' => $meal['meal_type'],
                    'title' => $meal['title'],
                    'description' => $meal['description'] ?? null,
                    'calories' => $meal['calories'] ?? null,
                    'protein' => $meal['protein'] ?? null,
                    'carbs' => $meal['carbs'] ?? null,
                    'fat' => $meal['fat'] ?? null,
                    'time' => $meal['time'] ?? null,
                    'sort_order' => $order,
                ]);
            }

            return $plan->load('meals');
        });
    }

    /**
     * Mifflin St Jeor with an activity factor, then shifted by the goal.
     * Falls back to a sane default when the member profile is incomplete.
     */
    public function estimateCalories(Member $member, string $goal = 'general_fitness'): int
    {
        $weight = (float) ($member->weight ?? 70);
        $height = (int) ($member->height ?? 170);
        $age = $member->age ?? 30;

        $bmr = $member->gender === 'female'
            ? (10 * $weight) + (6.25 * $height) - (5 * $age) - 161
            : (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;

        $maintenance = $bmr * 1.55;

        return (int) round(match ($goal) {
            'fat_loss' => $maintenance - 400,
            'muscle_gain' => $maintenance + 350,
            default => $maintenance,
        });
    }

    protected function memberBrief(Member $member): string
    {
        $latest = $member->measurements()->latest('measured_at')->first();

        return collect([
            'Gender: '.($member->gender ?? 'unspecified'),
            'Age: '.($member->age ?? 'unknown'),
            'Height: '.($member->height ?? 'unknown').' cm',
            'Weight: '.($member->weight ?? 'unknown').' kg',
            'Body fat: '.($latest?->fat_percent ?? 'unknown').'%',
            'Known conditions: '.($member->diseases ?: 'none'),
            'Allergies: '.($member->allergies ?: 'none'),
        ])->implode('. ');
    }

    protected function workoutSystemPrompt(): string
    {
        return <<<'PROMPT'
        You are a certified strength coach writing a program for a gym member.
        Return a program that respects the stated level, equipment and any medical
        condition or allergy mentioned. Never prescribe an exercise that would be
        unsafe given the listed conditions. Keep exercise names in English so the
        club's exercise library can match them. Prefer compound lifts first in each
        session, and keep each day between 4 and 8 exercises.
        PROMPT;
    }

    protected function nutritionSystemPrompt(): string
    {
        return <<<'PROMPT'
        You are a sports nutritionist writing a daily meal plan for a gym member.
        Respect every allergy and medical condition given. Meals should be built
        from ordinary supermarket ingredients. Hit the calorie target within about
        five percent and keep protein at roughly 1.6 to 2.2 grams per kilogram of
        body weight. This is general guidance, not medical advice.
        PROMPT;
    }

    /** @return array<string, mixed> */
    protected function workoutSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'days' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'day_number' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'notes' => ['type' => 'string'],
                            'exercises' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'sets' => ['type' => 'integer'],
                                        'reps' => ['type' => 'string'],
                                        'rest_seconds' => ['type' => 'integer'],
                                        'notes' => ['type' => 'string'],
                                    ],
                                    'required' => ['name', 'sets', 'reps', 'rest_seconds', 'notes'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['day_number', 'title', 'notes', 'exercises'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'notes', 'days'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function nutritionSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'daily_calories' => ['type' => 'integer'],
                'meals' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'meal_type' => [
                                'type' => 'string',
                                'enum' => ['breakfast', 'lunch', 'dinner', 'snack', 'supplement'],
                            ],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'calories' => ['type' => 'integer'],
                            'protein' => ['type' => 'number'],
                            'carbs' => ['type' => 'number'],
                            'fat' => ['type' => 'number'],
                            'time' => ['type' => 'string'],
                        ],
                        'required' => ['meal_type', 'title', 'description', 'calories', 'protein', 'carbs', 'fat', 'time'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['title', 'notes', 'daily_calories', 'meals'],
            'additionalProperties' => false,
        ];
    }

    /** The offline fallback: a classic split scaled to the training days. */
    protected function workoutTemplate(string $goal, int $days, string $level): array
    {
        $library = [
            'chest' => [['Bench Press', 4, '8-12'], ['Incline Dumbbell Press', 3, '10-12'], ['Cable Fly', 3, '12-15'], ['Push Up', 3, '15']],
            'back' => [['Lat Pulldown', 4, '10-12'], ['Barbell Row', 4, '8-12'], ['Seated Cable Row', 3, '12'], ['Face Pull', 3, '15']],
            'legs' => [['Back Squat', 4, '8-12'], ['Romanian Deadlift', 3, '10'], ['Leg Press', 3, '12-15'], ['Standing Calf Raise', 4, '15']],
            'shoulders' => [['Overhead Press', 4, '8-10'], ['Lateral Raise', 3, '12-15'], ['Rear Delt Fly', 3, '15'], ['Shrug', 3, '12']],
            'arms' => [['Barbell Curl', 3, '10-12'], ['Triceps Pushdown', 3, '12'], ['Hammer Curl', 3, '12'], ['Overhead Triceps Extension', 3, '12']],
            'full_body' => [['Goblet Squat', 3, '12'], ['Dumbbell Bench Press', 3, '12'], ['One Arm Row', 3, '12'], ['Plank', 3, '45s']],
        ];

        $split = match (true) {
            $days <= 2 => ['full_body', 'full_body'],
            $days === 3 => ['chest', 'back', 'legs'],
            $days === 4 => ['chest', 'back', 'legs', 'shoulders'],
            default => ['chest', 'back', 'legs', 'shoulders', 'arms'],
        };

        $sets = $level === 'beginner' ? -1 : 0;

        return [
            'title' => __('ai.default_workout_title'),
            'notes' => __('ai.template_notes'),
            'days' => collect($split)->take($days)->values()->map(fn (string $group, int $index) => [
                'day_number' => $index + 1,
                'title' => __("ai.muscle_{$group}"),
                'exercises' => collect($library[$group])->map(fn (array $exercise) => [
                    'name' => $exercise[0],
                    'sets' => max(2, $exercise[1] + $sets),
                    'reps' => $exercise[2],
                    'rest_seconds' => $goal === 'fat_loss' ? 45 : 90,
                ])->all(),
            ])->all(),
        ];
    }

    /** The offline fallback: a five meal split of the calorie target. */
    protected function nutritionTemplate(int $calories): array
    {
        $split = [
            ['breakfast', 0.25, '07:30'],
            ['snack', 0.10, '10:30'],
            ['lunch', 0.30, '13:00'],
            ['snack', 0.10, '17:00'],
            ['dinner', 0.25, '20:00'],
        ];

        return [
            'title' => __('ai.default_nutrition_title'),
            'notes' => __('ai.template_notes'),
            'daily_calories' => $calories,
            'meals' => collect($split)->map(fn (array $meal) => [
                'meal_type' => $meal[0],
                'title' => __("ai.meal_{$meal[0]}"),
                'description' => __('ai.meal_template_description'),
                'calories' => (int) round($calories * $meal[1]),
                'protein' => round($calories * $meal[1] * 0.3 / 4, 1),
                'carbs' => round($calories * $meal[1] * 0.45 / 4, 1),
                'fat' => round($calories * $meal[1] * 0.25 / 9, 1),
                'time' => $meal[2],
            ])->all(),
        ];
    }
}
