<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkoutPlanExercise extends Model
{
    use HasFactory;

    protected $fillable = [
        'workout_plan_day_id', 'exercise_id', 'name', 'sets', 'reps',
        'weight', 'rest_seconds', 'sort_order', 'notes',
    ];

    protected function casts(): array
    {
        return ['weight' => 'decimal:2'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlanDay::class, 'workout_plan_day_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** "4 × 12" as shown in the app. */
    public function getVolumeAttribute(): string
    {
        return $this->sets.' × '.$this->reps;
    }
}
