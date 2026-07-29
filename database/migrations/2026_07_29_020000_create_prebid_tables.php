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
            $table->string('version', 40);
            $table->string('file_path');
            $table->string('minified_path');
            $table->string('checksum', 64)->nullable();
            $table->json('modules');
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name', 'version'], 'prebid_builds_identity_unique');
        });

        Schema::create('prebid_adapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('display_name');
            $table->string('module_code', 120);
            $table->string('publisher_parameter', 120)->nullable();
            $table->string('placement_parameter', 120)->nullable();
            $table->json('required_public_parameters');
            $table->json('optional_public_parameters')->nullable();
            $table->json('supported_media_types');
            $table->json('supported_sizes')->nullable();
            $table->string('documentation_url');
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('prebid_bidders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('prebid_adapter_id')->constrained('prebid_adapters')->cascadeOnDelete();
            $table->string('code', 80);
            $table->string('display_name');
            $table->json('default_public_parameters')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'prebid_bidders_org_code_unique');
        });

        Schema::create('bidder_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('prebid_bidder_id')->constrained('prebid_bidders')->cascadeOnDelete();
            $table->string('name');
            $table->string('publisher_id')->nullable();
            $table->json('public_parameters')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'prebid_bidder_id', 'name'], 'bidder_accounts_identity_unique');
        });

        Schema::create('bidder_site_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_account_id')->constrained('bidder_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->json('public_parameters')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
            $table->unique(['bidder_account_id', 'site_id'], 'bidder_site_mappings_unique');
            $table->index(['site_id', 'enabled', 'sequence'], 'bidder_site_mappings_lookup_index');
        });

        Schema::create('bidder_placement_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_site_mapping_id')->constrained('bidder_site_mappings')->cascadeOnDelete();
            $table->foreignUlid('placement_id')->constrained('placements')->cascadeOnDelete();
            $table->string('placement_id_value')->nullable();
            $table->json('public_parameters')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
            $table->unique(['bidder_site_mapping_id', 'placement_id'], 'bidder_placement_mappings_unique');
            $table->index(['placement_id', 'enabled', 'sequence'], 'bidder_placement_mappings_lookup_index');
        });

        Schema::create('prebid_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->unique()->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_build_id')->nullable()->constrained('prebid_builds')->nullOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->unsignedSmallInteger('auction_timeout_ms')->default(1200);
            $table->string('price_granularity', 40)->default('medium');
            $table->string('currency', 8)->default('USD');
            $table->string('bidder_sequence', 20)->default('fixed');
            $table->json('consent_behavior')->nullable();
            $table->json('lazy_loading')->nullable();
            $table->json('refresh_behavior')->nullable();
            $table->boolean('bidder_timeout_reporting')->default(true);
            $table->boolean('gam_fallback')->default(true);
            $table->json('configuration')->nullable();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('prebid_price_buckets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('code', 80);
            $table->decimal('minimum', 10, 4)->default(0);
            $table->decimal('maximum', 10, 4);
            $table->decimal('increment', 10, 4);
            $table->unsignedTinyInteger('precision')->default(2);
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['gam_connection_id', 'code'], 'prebid_price_buckets_unique');
        });

        Schema::create('prebid_gam_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('name');
            $table->string('advertiser_name')->default('Horus Media Prebid');
            $table->string('order_name_prefix')->default('Horus Prebid');
            $table->string('line_item_name_template')->default('Prebid {{currency}} {{price}}');
            $table->string('creative_name')->default('Horus Universal Prebid Creative');
            $table->json('targeting')->nullable();
            $table->longText('creative_snippet');
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'name'], 'prebid_gam_templates_unique');
        });

        Schema::create('prebid_setup_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('status', 32)->default('PENDING')->index();
            $table->boolean('dry_run')->default(true);
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('estimated_objects')->default(0);
            $table->unsignedInteger('completed_objects')->default(0);
            $table->unsignedInteger('cursor')->default(0);
            $table->json('planned_objects');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['gam_connection_id', 'status'], 'prebid_setup_runs_lookup_index');
        });

        Schema::create('prebid_gam_remote_objects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_setup_run_id')->nullable()->constrained('prebid_setup_runs')->nullOnDelete();
            $table->string('local_object_type', 80);
            $table->string('local_object_id', 120);
            $table->string('remote_object_type', 80);
            $table->string('remote_object_id', 120);
            $table->string('idempotency_key', 191);
            $table->string('payload_hash', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'idempotency_key'], 'prebid_gam_remote_objects_idempotent');
            $table->index(['gam_connection_id', 'remote_object_type'], 'prebid_gam_remote_objects_lookup');
        });

        Schema::create('prebid_errors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignUlid('prebid_setup_run_id')->nullable()->constrained('prebid_setup_runs')->nullOnDelete();
            $table->string('category', 40)->index();
            $table->string('code', 120)->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prebid_errors');
        Schema::dropIfExists('prebid_gam_remote_objects');
        Schema::dropIfExists('prebid_setup_runs');
        Schema::dropIfExists('prebid_gam_templates');
        Schema::dropIfExists('prebid_price_buckets');
        Schema::dropIfExists('prebid_settings');
        Schema::dropIfExists('bidder_placement_mappings');
        Schema::dropIfExists('bidder_site_mappings');
        Schema::dropIfExists('bidder_accounts');
        Schema::dropIfExists('prebid_bidders');
        Schema::dropIfExists('prebid_adapters');
        Schema::dropIfExists('prebid_builds');
    }
};
