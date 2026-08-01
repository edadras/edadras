<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coaches, classes, bookings, workout / nutrition programs and body metrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('photo_path')->nullable();
            $table->json('specialties')->nullable();
            $table->text('bio')->nullable();
            $table->string('contract_type')->default('fixed'); // fixed, percentage, per_session
            $table->decimal('salary_amount', 12, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->date('hired_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        // A class definition: Pilates, Yoga, Swimming, TRX, a pool lane...
        Schema::create('gym_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('kind')->default('class'); // class, pool, private
            $table->unsignedInteger('capacity')->default(20);
            $table->unsignedInteger('duration_minutes')->default(60);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('gender')->nullable(); // male, female, mixed
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'kind', 'is_active']);
        });

        // A concrete occurrence of a class (also used for pool "sans").
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gym_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('booked_count')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('status')->default('scheduled'); // scheduled, running, done, cancelled
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['gym_class_id', 'starts_at']);
        });

        Schema::create('class_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('booked'); // booked, attended, cancelled, no_show
            $table->decimal('price', 12, 2)->default(0);
            $table->timestamp('booked_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['class_session_id', 'member_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('muscle_group')->nullable(); // chest, back, legs, shoulders...
            $table->string('equipment')->nullable();
            $table->string('media_url')->nullable();
            $table->json('instructions')->nullable();
            $table->timestamps();

            $table->index('muscle_group');
        });

        Schema::create('workout_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->string('goal')->nullable(); // fat_loss, muscle_gain, endurance...
            $table->boolean('generated_by_ai')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'member_id']);
        });

        Schema::create('workout_plan_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->string('title')->nullable(); // "Chest", "Legs"...
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workout_plan_id', 'day_number']);
        });

        Schema::create('workout_plan_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_plan_day_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sets')->default(3);
            $table->string('reps')->default('12'); // may be "12" or "10-12" or "to failure"
            $table->decimal('weight', 6, 2)->nullable();
            $table->unsignedInteger('rest_seconds')->default(60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('nutrition_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coach_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->unsignedInteger('daily_calories')->nullable();
            $table->text('notes')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('generated_by_ai')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'member_id']);
        });

        Schema::create('nutrition_plan_meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutrition_plan_id')->constrained()->cascadeOnDelete();
            $table->string('meal_type'); // breakfast, lunch, dinner, snack, supplement
            $table->unsignedSmallInteger('day_number')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('calories')->nullable();
            $table->decimal('protein', 6, 2)->nullable();
            $table->decimal('carbs', 6, 2)->nullable();
            $table->decimal('fat', 6, 2)->nullable();
            $table->string('time')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('body_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('measured_at');
            $table->decimal('weight', 5, 2)->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->decimal('fat_percent', 5, 2)->nullable();
            $table->decimal('muscle_mass', 5, 2)->nullable();
            $table->decimal('arm', 5, 2)->nullable();
            $table->decimal('chest', 5, 2)->nullable();
            $table->decimal('waist', 5, 2)->nullable();
            $table->decimal('hip', 5, 2)->nullable();
            $table->decimal('thigh', 5, 2)->nullable();
            $table->decimal('calf', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('body_measurements');
        Schema::dropIfExists('nutrition_plan_meals');
        Schema::dropIfExists('nutrition_plans');
        Schema::dropIfExists('workout_plan_exercises');
        Schema::dropIfExists('workout_plan_days');
        Schema::dropIfExists('workout_plans');
        Schema::dropIfExists('exercises');
        Schema::dropIfExists('class_bookings');
        Schema::dropIfExists('class_sessions');
        Schema::dropIfExists('gym_classes');
        Schema::dropIfExists('coaches');
    }
};
