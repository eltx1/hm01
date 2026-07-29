<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advertisers', function (Blueprint $table): void {
            $table->string('public_key', 40)->nullable()->unique()->after('id');
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->foreignUlid('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable()->after('reviewed_by');
        });

        Schema::create('advertiser_users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 40)->default('MEMBER');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['advertiser_id', 'user_id'], 'advertiser_users_unique');
            $table->index(['organization_id', 'advertiser_id'], 'advertiser_users_lookup');
        });

        Schema::create('advertiser_billing_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('billing_email');
            $table->string('currency', 3)->default('USD');
            $table->string('country_code', 2);
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->text('tax_identifier')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->boolean('is_default')->default(true);
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->timestamps();
            $table->index(['organization_id', 'advertiser_id'], 'advertiser_billing_lookup');
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('public_key', 40)->unique();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('objective', 80);
            $table->string('pricing_model', 32)->index();
            $table->string('status', 32)->default('DRAFT')->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('currency', 3)->default('USD');
            $table->unsignedBigInteger('total_budget_minor')->default(0);
            $table->unsignedBigInteger('daily_budget_minor')->nullable();
            $table->unsignedInteger('frequency_cap_impressions')->nullable();
            $table->unsignedSmallInteger('frequency_cap_days')->nullable();
            $table->string('landing_url', 2048)->nullable();
            $table->text('advertiser_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'advertiser_id', 'status'], 'campaigns_advertiser_status');
            $table->index(['status', 'starts_at', 'ends_at'], 'campaigns_schedule_lookup');
        });

        Schema::create('campaign_goals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('goal_type', 40);
            $table->unsignedBigInteger('target_value');
            $table->unsignedBigInteger('delivered_value')->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'goal_type'], 'campaign_goals_unique');
        });

        Schema::create('campaign_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 40);
            $table->string('operator', 16)->default('INCLUDE');
            $table->json('values');
            $table->timestamps();
            $table->unique(['campaign_id', 'dimension', 'operator'], 'campaign_targets_unique');
        });

        Schema::create('campaign_sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->decimal('budget_weight', 8, 4)->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['campaign_id', 'site_id'], 'campaign_sites_unique');
            $table->index(['site_id', 'is_active'], 'campaign_sites_site_active');
        });

        Schema::create('campaign_placements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('placement_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->unique(['campaign_id', 'placement_id'], 'campaign_placements_unique');
        });

        Schema::create('campaign_creatives', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('replaces_creative_id')->nullable()->constrained('campaign_creatives')->nullOnDelete();
            $table->string('name');
            $table->string('type', 32)->index();
            $table->string('status', 32)->default('DRAFT')->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('landing_url', 2048)->nullable();
            $table->string('click_through_url', 2048)->nullable();
            $table->longText('html_content')->nullable();
            $table->string('vast_url', 2048)->nullable();
            $table->json('native_assets')->nullable();
            $table->text('text_content')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status', 'is_active'], 'campaign_creatives_lookup');
        });

        Schema::create('creative_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_creative_id')->constrained('campaign_creatives')->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 1000);
            $table->string('original_name');
            $table->string('mime_type', 120);
            $table->string('extension', 20);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->json('asset_manifest')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'sha256'], 'creative_files_duplicate_guard');
            $table->index('campaign_creative_id');
        });

        Schema::create('campaign_budgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pricing_model', 32);
            $table->string('currency', 3);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedBigInteger('daily_minor')->nullable();
            $table->unsignedBigInteger('unit_price_minor')->default(0);
            $table->unsignedBigInteger('allocated_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->unsignedBigInteger('bonus_units')->default(0);
            $table->text('bonus_note')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_network_instances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('network_type', 32);
            $table->string('network_code', 64)->nullable();
            $table->string('status', 32)->default('PENDING')->index();
            $table->unsignedBigInteger('budget_allocated_minor')->default(0);
            $table->json('site_ids');
            $table->json('placement_ids')->nullable();
            $table->json('deployment_plan')->nullable();
            $table->unsignedInteger('planned_objects')->default(0);
            $table->unsignedInteger('completed_objects')->default(0);
            $table->unsignedInteger('cursor')->default(0);
            $table->text('last_error')->nullable();
            $table->string('remote_status', 64)->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('drift_detected_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id', 'gam_connection_id'], 'campaign_network_instances_unique');
            $table->index(['gam_connection_id', 'status'], 'campaign_network_instances_lookup');
        });

        Schema::create('campaign_delivery_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_network_instance_id')->constrained('campaign_network_instances')->cascadeOnDelete();
            $table->date('report_date');
            $table->string('source', 40)->default('GAM');
            $table->string('external_report_id', 128);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('spend_minor')->default(0);
            $table->json('dimensions')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_network_instance_id', 'report_date', 'source', 'external_report_id'], 'campaign_delivery_unique');
            $table->index(['campaign_id', 'report_date'], 'campaign_delivery_campaign_date');
        });

        Schema::create('campaign_approval_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_creative_id')->nullable()->constrained('campaign_creatives')->nullOnDelete();
            $table->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['campaign_id', 'created_at'], 'campaign_approval_campaign_date');
        });

        Schema::create('advertiser_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_billing_profile_id')->nullable()->constrained('advertiser_billing_profiles')->nullOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 64)->unique();
            $table->string('status', 24)->default('DRAFT')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->date('issued_on')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('line_items');
            $table->string('file_path')->nullable();
            $table->timestamps();
            $table->index(['advertiser_id', 'status'], 'advertiser_invoices_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertiser_invoices');
        Schema::dropIfExists('campaign_approval_logs');
        Schema::dropIfExists('campaign_delivery_logs');
        Schema::dropIfExists('campaign_network_instances');
        Schema::dropIfExists('campaign_budgets');
        Schema::dropIfExists('creative_files');
        Schema::dropIfExists('campaign_creatives');
        Schema::dropIfExists('campaign_placements');
        Schema::dropIfExists('campaign_sites');
        Schema::dropIfExists('campaign_targets');
        Schema::dropIfExists('campaign_goals');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('advertiser_billing_profiles');
        Schema::dropIfExists('advertiser_users');

        Schema::table('advertisers', function (Blueprint $table): void {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['public_key', 'reviewed_at', 'reviewed_by', 'review_notes']);
        });
    }
};
