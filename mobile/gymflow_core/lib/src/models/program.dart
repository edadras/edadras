/// A workout program: days, each holding its exercises.
class WorkoutPlan {
  const WorkoutPlan({
    required this.id,
    required this.title,
    required this.days,
    this.notes,
    this.generatedByAi = false,
  });

  factory WorkoutPlan.fromJson(Map<String, dynamic> json) => WorkoutPlan(
        id: json['id'] as int,
        title: json['title'] as String? ?? '',
        notes: json['notes'] as String?,
        generatedByAi: json['generated_by_ai'] as bool? ?? false,
        days: ((json['days'] as List?) ?? const [])
            .cast<Map>()
            .map((day) => WorkoutDay.fromJson(day.cast<String, dynamic>()))
            .toList(),
      );

  final int id;
  final String title;
  final String? notes;
  final bool generatedByAi;
  final List<WorkoutDay> days;
}

class WorkoutDay {
  const WorkoutDay({required this.dayNumber, required this.exercises, this.title});

  factory WorkoutDay.fromJson(Map<String, dynamic> json) => WorkoutDay(
        dayNumber: (json['day_number'] as num?)?.toInt() ?? 1,
        title: json['title'] as String?,
        exercises: ((json['exercises'] as List?) ?? const [])
            .cast<Map>()
            .map((exercise) => WorkoutExercise.fromJson(exercise.cast<String, dynamic>()))
            .toList(),
      );

  final int dayNumber;
  final String? title;
  final List<WorkoutExercise> exercises;
}

class WorkoutExercise {
  const WorkoutExercise({
    required this.name,
    required this.sets,
    required this.reps,
    this.restSeconds = 60,
    this.notes,
  });

  factory WorkoutExercise.fromJson(Map<String, dynamic> json) => WorkoutExercise(
        name: json['name'] as String? ?? '',
        sets: (json['sets'] as num?)?.toInt() ?? 3,
        reps: '${json['reps'] ?? ''}',
        restSeconds: (json['rest_seconds'] as num?)?.toInt() ?? 60,
        notes: json['notes'] as String?,
      );

  final String name;
  final int sets;
  final String reps;
  final int restSeconds;
  final String? notes;

  /// "4 × 12", the way it reads on the card.
  String get volume => '$sets × $reps';
}

/// A meal plan and its meals.
class NutritionPlan {
  const NutritionPlan({
    required this.id,
    required this.title,
    required this.meals,
    this.dailyCalories,
    this.notes,
    this.generatedByAi = false,
  });

  factory NutritionPlan.fromJson(Map<String, dynamic> json) => NutritionPlan(
        id: json['id'] as int,
        title: json['title'] as String? ?? '',
        dailyCalories: (json['daily_calories'] as num?)?.toInt(),
        notes: json['notes'] as String?,
        generatedByAi: json['generated_by_ai'] as bool? ?? false,
        meals: ((json['meals'] as List?) ?? const [])
            .cast<Map>()
            .map((meal) => Meal.fromJson(meal.cast<String, dynamic>()))
            .toList(),
      );

  final int id;
  final String title;
  final int? dailyCalories;
  final String? notes;
  final bool generatedByAi;
  final List<Meal> meals;
}

class Meal {
  const Meal({
    required this.type,
    required this.title,
    this.description,
    this.calories,
    this.time,
  });

  factory Meal.fromJson(Map<String, dynamic> json) => Meal(
        type: json['meal_type'] as String? ?? 'snack',
        title: json['title'] as String? ?? '',
        description: json['description'] as String?,
        calories: (json['calories'] as num?)?.toInt(),
        time: json['time'] as String?,
      );

  final String type;
  final String title;
  final String? description;
  final int? calories;
  final String? time;
}
