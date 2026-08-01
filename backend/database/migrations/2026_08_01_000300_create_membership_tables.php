<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members, membership plans, the session quota engine and the check-in log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code'); // human readable member number
            $table->string('first_name');
            $table->string('last_name');
            $table->string('national_id')->nullable();
            // Encrypted at rest, so the ciphertext needs the room a string
            // column does not have.
            $table->text('passport_no')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('gender')->nullable(); // male, female, other
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('height')->nullable(); // cm
            $table->decimal('weight', 5, 2)->nullable(); // kg
            $table->string('blood_type', 5)->nullable();
            $table->text('diseases')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('qr_token', 64)->unique();
            $table->string('nfc_uid')->nullable();
            // Set once the member starts the club's Telegram bot; without it
            // the Telegram channel has no one to write to.
            $table->string('telegram_chat_id')->nullable()->index();
            $table->string('emergency_name')->nullable();
            $table->string('emergency_phone')->nullable();
            $table->string('status')->default('active'); // active, inactive, blocked
            $table->date('joined_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'phone']);
            $table->index('nfc_uid');
        });

        // A price list entry: 1 month, 3 months, 10 sessions, unlimited...
        Schema::create('membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->json('description')->nullable();
            $table->string('type')->default('duration'); // duration, session, unlimited
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('session_count')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('freeze_days')->default(0);
            $table->string('color', 9)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // A member actually buying one of those plans.
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('duration');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->unsignedInteger('total_sessions')->nullable();
            $table->unsignedInteger('remaining_sessions')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status')->default('active'); // active, expired, frozen, cancelled
            $table->date('frozen_from')->nullable();
            $table->date('frozen_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'ends_at']);
            $table->index(['member_id', 'status']);
        });

        // One row per gate pass. check_out_at is filled when the member leaves.
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->string('method')->default('qr'); // qr, nfc, manual
            $table->string('device')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('consumed_session')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'checked_in_at']);
            $table->index(['member_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_plans');
        Schema::dropIfExists('members');
    }
};
