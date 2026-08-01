<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform level tables. These rows live above the tenants and are managed
 * by the Super Admin panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SaaS subscription plans (Free / Basic / Professional / Enterprise).
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->json('name');
            $table->json('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('IRR');
            $table->string('period')->default('monthly'); // monthly, yearly, lifetime
            $table->unsignedInteger('trial_days')->default(0);
            $table->unsignedInteger('max_members')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->unsignedInteger('max_branches')->default(1);
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // A tenant is one club: gym, pool, martial arts hall, yoga studio...
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type')->default('gym'); // gym, pool, martial_arts, yoga, pilates, crossfit, football, multi
            $table->string('logo_path')->nullable();
            $table->string('brand_color', 9)->default('#5EF38C');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->nullable();
            $table->json('socials')->nullable();
            $table->json('working_hours')->nullable();
            $table->text('rules')->nullable();
            $table->string('timezone')->default('Asia/Tehran');
            $table->string('locale', 5)->default('fa');
            $table->string('currency', 3)->default('IRR');
            $table->string('status')->default('active'); // active, suspended, pending
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
        });

        // Which SaaS plan a tenant is currently paying for.
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('status')->default('active'); // active, expired, cancelled, trialing
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        // Every user facing string is translated from the database.
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('locale', 5);
            $table->string('group')->default('general');
            $table->string('key');
            $table->text('value');
            $table->timestamps();

            $table->unique(['tenant_id', 'locale', 'group', 'key'], 'translations_unique');
            $table->index(['locale', 'group']);
        });

        // Free form per tenant configuration (null tenant = global default).
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('plans');
    }
};
