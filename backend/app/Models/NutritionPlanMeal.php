<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NutritionPlanMeal extends Model
{
    use HasFactory;

    public const TYPES = ['breakfast', 'lunch', 'dinner', 'snack', 'supplement'];

    protected $fillable = [
        'nutrition_plan_id', 'meal_type', 'day_number', 'title', 'description',
        'calories', 'protein', 'carbs', 'fat', 'time', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'protein' => 'decimal:2',
            'carbs' => 'decimal:2',
            'fat' => 'decimal:2',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(NutritionPlan::class, 'nutrition_plan_id');
    }
}
