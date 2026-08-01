<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users, roles and the per tenant access control layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('phone')->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->string('locale', 5)->default('fa')->after('avatar_path');
            $table->boolean('is_super_admin')->default(false)->after('locale');
            $table->string('status')->default('active')->after('is_super_admin');
            $table->string('two_factor_secret')->nullable()->after('status');
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
            // Hashed, one use each, for the day the phone is lost.
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_enabled');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('last_login_at')->nullable()->after('two_factor_enabled');
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        // Email is only unique inside a tenant, the same person may belong to
        // two clubs with the same address.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->json('name');
            $table->json('permissions')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'user_id']);
        });

        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token');
            $table->string('platform')->default('android'); // android, ios, web
            $table->string('device_name')->nullable();
            $table->timestamps();

            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn([
                'phone', 'avatar_path', 'locale', 'is_super_admin', 'status',
                'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes',
                'two_factor_confirmed_at', 'last_login_at', 'deleted_at',
            ]);
            $table->unique('email');
        });
    }
};
