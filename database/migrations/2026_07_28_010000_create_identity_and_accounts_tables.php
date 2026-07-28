<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type', 32)->index();
            $table->string('status', 24)->default('PENDING')->index();
            $table->string('dashboard_title')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 16)->nullable();
            $table->string('support_email')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUlid('organization_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestamp('last_failed_login_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->softDeletes();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('display_name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120)->unique();
            $table->string('display_name');
            $table->string('group', 64)->index();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('role_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::create('login_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->boolean('successful');
            $table->string('failure_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('publishers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('status', 24)->default('PENDING')->index();
            $table->string('billing_email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('dashboard_title')->nullable();
            $table->string('primary_color', 16)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('publisher_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'publisher_id']);
        });

        Schema::create('advertisers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('status', 24)->default('PENDING')->index();
            $table->string('billing_email')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('dashboard_title')->nullable();
            $table->string('primary_color', 16)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('advertiser_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('title')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'advertiser_id']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->dropForeign(['organization_id']));
        Schema::dropIfExists('advertiser_contacts');
        Schema::dropIfExists('advertisers');
        Schema::dropIfExists('publisher_contacts');
        Schema::dropIfExists('publishers');
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'status', 'activated_at', 'suspended_at', 'last_login_at', 'last_login_ip', 'failed_login_count', 'last_failed_login_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'deleted_at']);
        });
        Schema::dropIfExists('organizations');
    }
};
