<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishers', function (Blueprint $table): void {
            $table->unsignedTinyInteger('onboarding_step')->default(1);
            $table->timestamp('onboarding_submitted_at')->nullable();
        });

        Schema::create('publisher_payment_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('beneficiary_name');
            $table->string('payment_method', 32);
            $table->char('currency', 3)->default('USD');
            $table->char('country', 2);
            $table->string('billing_address')->nullable();
            $table->text('payment_details')->nullable();
            $table->string('account_last_four', 4)->nullable();
            $table->text('tax_identifier')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'publisher_id']);
        });

        Schema::create('publisher_contracts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->string('contract_reference');
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('auto_renews')->default(false);
            $table->decimal('revenue_share_percent', 5, 2);
            $table->decimal('payment_threshold', 15, 2)->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('payment_terms', 100);
            $table->string('contract_file_path')->nullable();
            $table->string('contract_file_name')->nullable();
            $table->string('contract_file_mime', 100)->nullable();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->text('internal_notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['publisher_id', 'contract_reference']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->string('public_key', 40)->unique();
            $table->string('display_name');
            $table->string('primary_domain');
            $table->string('language', 12)->default('en');
            $table->string('content_category', 100);
            $table->char('country', 2);
            $table->json('main_traffic_countries')->nullable();
            $table->unsignedBigInteger('estimated_monthly_pageviews')->default(0);
            $table->unsignedBigInteger('estimated_monthly_users')->default(0);
            $table->json('current_monetization_providers')->nullable();
            $table->string('current_gam_network_code')->nullable();
            $table->string('current_adsense_status', 24)->nullable();
            $table->string('current_adx_status', 24)->nullable();
            $table->boolean('prebid_enabled')->default(false);
            $table->boolean('native_demand_enabled')->default(false);
            $table->decimal('default_revenue_share_percent', 5, 2);
            $table->string('serving_mode', 32)->default('HORUS_GAM')->index();
            $table->string('status', 32)->default('DRAFT')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['publisher_id', 'primary_domain']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('site_domains', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 24)->default('PENDING')->index();
            $table->string('verification_method', 32)->nullable();
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'domain']);
            $table->index(['organization_id', 'domain']);
        });

        Schema::create('site_verifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_domain_id')->constrained()->cascadeOnDelete();
            $table->string('method', 32);
            $table->string('status', 24)->default('PENDING')->index();
            $table->string('expected_value');
            $table->json('evidence')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'site_id']);
        });

        Schema::create('site_reviews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 32)->default('PENDING');
            $table->text('publisher_message')->nullable();
            $table->text('internal_reason')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'site_id', 'decision']);
        });

        Schema::create('site_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');
            $table->timestamps();
            $table->index(['organization_id', 'site_id']);
        });

        Schema::create('site_status_history', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32);
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'site_id', 'created_at']);
        });

        Schema::create('site_serving_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('serving_mode', 32)->default('HORUS_GAM');
            $table->decimal('revenue_share_percent', 5, 2);
            $table->boolean('prebid_enabled')->default(false);
            $table->boolean('native_demand_enabled')->default(false);
            $table->json('placement_plan')->nullable();
            $table->unsignedBigInteger('configuration_version')->default(1);
            $table->timestamps();
            $table->index(['organization_id', 'serving_mode']);
        });

        Schema::create('serving_mode_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('previous_mode', 32)->nullable();
            $table->string('new_mode', 32);
            $table->foreignUlid('administrator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->ulid('rollback_reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('rollback_reference_id')->references('id')->on('serving_mode_changes')->nullOnDelete();
            $table->index(['organization_id', 'site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serving_mode_changes');
        Schema::dropIfExists('site_serving_settings');
        Schema::dropIfExists('site_status_history');
        Schema::dropIfExists('site_notes');
        Schema::dropIfExists('site_reviews');
        Schema::dropIfExists('site_verifications');
        Schema::dropIfExists('site_domains');
        Schema::dropIfExists('sites');
        Schema::dropIfExists('publisher_contracts');
        Schema::dropIfExists('publisher_payment_profiles');
        Schema::table('publishers', fn (Blueprint $table) => $table->dropColumn(['onboarding_step', 'onboarding_submitted_at']));
    }
};
