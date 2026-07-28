<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prebid_builds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('version', 40)->unique();
            $table->string('prebid_version', 40);
            $table->string('source_repository');
            $table->string('source_reference', 100);
            $table->json('modules');
            $table->string('source_path')->nullable();
            $table->string('asset_path');
            $table->string('minified_path');
            $table->string('manifest_path');
            $table->string('checksum', 64)->nullable();
            $table->string('status', 24)->default('PLANNED')->index();
            $table->boolean('is_active')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('prebid_adapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('bidder_code', 64)->unique();
            $table->string('module_name', 120)->unique();
            $table->string('display_name');
            $table->json('required_public_parameters');
            $table->json('optional_public_parameters')->nullable();
            $table->json('supported_media_types');
            $table->json('supported_sizes')->nullable();
            $table->string('documentation_url');
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prebid_bidders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('prebid_adapter_id')->constrained('prebid_adapters')->cascadeOnDelete();
            $table->string('code', 64)->unique();
            $table->string('display_name');
            $table->string('alias_of', 64)->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->json('defaults')->nullable();
            $table->timestamps();
        });

        Schema::create('bidder_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('prebid_bidder_id')->constrained('prebid_bidders')->cascadeOnDelete();
            $table->string('name');
            $table->string('publisher_id')->nullable();
            $table->string('account_code')->nullable();
            $table->json('public_parameters')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('approval_status', 24)->default('APPROVED')->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'name'], 'bidder_accounts_org_name_unique');
        });

        Schema::create('prebid_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('prebid_build_id')->nullable()->constrained('prebid_builds')->nullOnDelete();
            $table->boolean('is_enabled')->default(false)->index();
            $table->unsignedSmallInteger('auction_timeout_ms')->default(1200);
            $table->string('price_granularity', 32)->default('DENSE');
            $table->char('currency', 3)->default('USD');
            $table->string('bidder_sequence', 16)->default('RANDOM');
            $table->json('consent_config')->nullable();
            $table->json('user_sync_config')->nullable();
            $table->boolean('lazy_loading_enabled')->default(true);
            $table->boolean('refresh_enabled')->default(false);
            $table->unsignedSmallInteger('refresh_interval_seconds')->nullable();
            $table->boolean('timeout_reporting_enabled')->default(true);
            $table->boolean('gam_fallback_enabled')->default(true);
            $table->boolean('send_all_bids')->default(false);
            $table->boolean('debug_enabled')->default(false);
            $table->unsignedInteger('configuration_version')->default(1);
            $table->json('advanced_config')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'is_enabled'], 'prebid_settings_org_enabled_index');
        });

        Schema::create('bidder_site_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_account_id')->constrained('bidder_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('publisher_id')->nullable();
            $table->json('public_parameters')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedSmallInteger('sequence')->default(100);
            $table->timestamps();
            $table->unique(['bidder_account_id', 'site_id'], 'bidder_site_mapping_unique');
            $table->index(['organization_id', 'site_id', 'is_enabled'], 'bidder_site_mapping_lookup');
        });

        Schema::create('bidder_placement_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_site_mapping_id')->constrained('bidder_site_mappings')->cascadeOnDelete();
            $table->foreignUlid('placement_id')->constrained('placements')->cascadeOnDelete();
            $table->string('placement_id_value')->nullable();
            $table->json('public_parameters')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->unsignedSmallInteger('sequence')->default(100);
            $table->timestamps();
            $table->unique(['bidder_site_mapping_id', 'placement_id'], 'bidder_placement_mapping_unique');
            $table->index(['organization_id', 'placement_id', 'is_enabled'], 'bidder_placement_mapping_lookup');
        });

        Schema::create('prebid_price_buckets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('prebid_setting_id')->nullable()->constrained('prebid_settings')->cascadeOnDelete();
            $table->string('label', 64);
            $table->decimal('minimum', 10, 4)->default(0);
            $table->decimal('maximum', 10, 4);
            $table->decimal('increment', 10, 4);
            $table->unsignedTinyInteger('precision')->default(2);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();
            $table->index(['prebid_setting_id', 'priority'], 'prebid_price_bucket_order');
        });

        Schema::create('prebid_gam_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('name');
            $table->string('mode', 32)->default('TOP_PRICE');
            $table->string('advertiser_name')->default('Horus Media Prebid');
            $table->string('order_name_prefix')->default('Horus Prebid');
            $table->json('targeting_keys');
            $table->text('universal_creative_template');
            $table->string('line_item_type', 32)->default('PRICE_PRIORITY');
            $table->unsignedInteger('line_item_priority')->default(12);
            $table->char('currency', 3)->default('USD');
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->unsignedInteger('version')->default(1);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'name'], 'prebid_gam_template_connection_name_unique');
        });

        Schema::create('prebid_setup_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_gam_template_id')->constrained('prebid_gam_templates')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('PREVIEW')->index();
            $table->boolean('dry_run')->default(true);
            $table->string('confirmation_token_hash', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->json('plan');
            $table->json('counters')->nullable();
            $table->unsignedInteger('cursor')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'gam_connection_id', 'created_at'], 'prebid_setup_runs_lookup');
        });

        Schema::create('prebid_gam_remote_objects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_gam_template_id')->constrained('prebid_gam_templates')->cascadeOnDelete();
            $table->foreignUlid('prebid_setup_run_id')->nullable()->constrained('prebid_setup_runs')->nullOnDelete();
            $table->string('object_key', 160);
            $table->string('remote_object_type', 80);
            $table->string('remote_object_id', 128);
            $table->string('payload_hash', 64)->nullable();
            $table->string('remote_status', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'object_key'], 'prebid_gam_remote_object_key_unique');
            $table->unique(['gam_connection_id', 'remote_object_type', 'remote_object_id'], 'prebid_gam_remote_upstream_unique');
        });

        Schema::create('prebid_errors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->nullOnDelete();
            $table->foreignUlid('prebid_setup_run_id')->nullable()->constrained('prebid_setup_runs')->nullOnDelete();
            $table->string('category', 32)->default('UNKNOWN')->index();
            $table->string('code')->nullable();
            $table->text('message');
            $table->boolean('retryable')->default(false);
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'occurred_at'], 'prebid_errors_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prebid_errors');
        Schema::dropIfExists('prebid_gam_remote_objects');
        Schema::dropIfExists('prebid_setup_runs');
        Schema::dropIfExists('prebid_gam_templates');
        Schema::dropIfExists('prebid_price_buckets');
        Schema::dropIfExists('bidder_placement_mappings');
        Schema::dropIfExists('bidder_site_mappings');
        Schema::dropIfExists('prebid_settings');
        Schema::dropIfExists('bidder_accounts');
        Schema::dropIfExists('prebid_bidders');
        Schema::dropIfExists('prebid_adapters');
        Schema::dropIfExists('prebid_builds');
    }
};
