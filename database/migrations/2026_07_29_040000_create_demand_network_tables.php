<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->string('connector_class');
            $table->string('default_integration_mode', 40)->default('DIRECT_JS');
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('supports_direct_js')->default(false);
            $table->boolean('supports_gam_creative')->default(false);
            $table->boolean('supports_gam_line_item')->default(false);
            $table->boolean('supports_api')->default(false);
            $table->json('script_origins')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('demand_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('public_key', 40)->unique();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_network_id')->constrained('demand_networks')->restrictOnDelete();
            $table->foreignUlid('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('partner_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->string('scope', 32)->index();
            $table->string('integration_mode', 40)->index();
            $table->string('approval_status', 32)->default('NOT_SUBMITTED')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_default')->default(false)->index();
            $table->decimal('revenue_share_percent', 6, 3)->default(0);
            $table->unsignedInteger('fallback_priority')->default(100);
            $table->string('account_identifier')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['organization_id', 'demand_network_id', 'is_enabled'], 'demand_accounts_lookup');
            $table->index(['publisher_id', 'approval_status'], 'demand_accounts_publisher_status');
        });

        Schema::create('demand_account_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->string('credential_key', 80);
            $table->text('reference');
            $table->string('hint')->nullable();
            $table->string('capability', 32)->default('API');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['demand_account_id', 'credential_key'], 'demand_credentials_unique');
        });

        Schema::create('demand_sites', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('approval_status', 32)->default('NOT_SUBMITTED')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('is_default')->default(false);
            $table->string('integration_mode', 40)->nullable();
            $table->decimal('revenue_share_percent', 6, 3)->nullable();
            $table->unsignedInteger('fallback_priority')->nullable();
            $table->string('remote_site_id', 128)->nullable();
            $table->json('configuration')->nullable();
            $table->string('sync_status', 32)->default('PENDING')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['demand_account_id', 'site_id'], 'demand_sites_unique');
            $table->index(['site_id', 'approval_status', 'is_enabled'], 'demand_sites_delivery_lookup');
        });

        Schema::create('demand_placements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_site_id')->constrained('demand_sites')->cascadeOnDelete();
            $table->foreignUlid('placement_id')->constrained()->cascadeOnDelete();
            $table->string('approval_status', 32)->default('NOT_SUBMITTED')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('integration_mode', 40)->nullable();
            $table->unsignedInteger('fallback_priority')->nullable();
            $table->string('remote_placement_id', 128)->nullable();
            $table->string('placement_code', 255)->nullable();
            $table->json('configuration')->nullable();
            $table->string('sync_status', 32)->default('PENDING')->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['demand_site_id', 'placement_id'], 'demand_placements_unique');
            $table->index(['placement_id', 'approval_status', 'is_enabled'], 'demand_placements_delivery_lookup');
        });

        Schema::create('demand_widgets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_placement_id')->constrained('demand_placements')->cascadeOnDelete();
            $table->string('name');
            $table->string('remote_widget_id', 128)->nullable();
            $table->string('widget_code', 255)->nullable();
            $table->string('integration_mode', 40)->nullable();
            $table->longText('direct_tag_template')->nullable();
            $table->longText('gam_creative_template')->nullable();
            $table->string('approval_status', 32)->default('NOT_SUBMITTED')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->json('configuration')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->index(['demand_placement_id', 'approval_status', 'is_enabled'], 'demand_widgets_lookup');
        });

        Schema::create('demand_ads_txt_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('domain');
            $table->string('publisher_account_id');
            $table->string('relationship', 16);
            $table->string('certification_authority_id')->nullable();
            $table->string('record_hash', 64);
            $table->text('raw_record');
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->string('source', 32)->default('CONNECTOR');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['demand_account_id', 'site_id', 'record_hash'], 'demand_ads_txt_unique');
        });

        Schema::create('demand_report_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('import_type', 16);
            $table->string('status', 24)->default('PENDING')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('external_report_id', 255)->nullable();
            $table->string('source_file_path', 1000)->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->json('normalized_rows')->nullable();
            $table->json('totals')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['demand_account_id', 'period_start', 'period_end'], 'demand_reports_account_period');
        });

        Schema::create('demand_remote_objects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->nullOnDelete();
            $table->string('connection_key', 64)->default('DIRECT');
            $table->string('local_object_type', 64);
            $table->string('local_object_id', 64);
            $table->string('remote_object_type', 64);
            $table->string('remote_object_id', 255);
            $table->string('idempotency_key', 64)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('remote_status', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['demand_account_id', 'connection_key', 'local_object_type', 'local_object_id', 'remote_object_type'],
                'demand_remote_objects_local_unique'
            );
            $table->index(['remote_object_type', 'remote_object_id'], 'demand_remote_objects_remote_lookup');
        });

        Schema::create('demand_sync_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->foreignUlid('demand_site_id')->nullable()->constrained('demand_sites')->nullOnDelete();
            $table->foreignUlid('demand_placement_id')->nullable()->constrained('demand_placements')->nullOnDelete();
            $table->string('level', 16)->default('INFO')->index();
            $table->string('action', 80)->index();
            $table->boolean('dry_run')->default(false);
            $table->string('idempotency_key', 64)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['demand_account_id', 'created_at'], 'demand_sync_account_date');
        });

        Schema::create('demand_errors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('demand_account_id')->constrained('demand_accounts')->cascadeOnDelete();
            $table->foreignUlid('demand_site_id')->nullable()->constrained('demand_sites')->nullOnDelete();
            $table->foreignUlid('demand_placement_id')->nullable()->constrained('demand_placements')->nullOnDelete();
            $table->string('category', 48)->index();
            $table->string('code', 100)->nullable();
            $table->text('message');
            $table->boolean('retryable')->default(false)->index();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_errors');
        Schema::dropIfExists('demand_sync_logs');
        Schema::dropIfExists('demand_remote_objects');
        Schema::dropIfExists('demand_report_imports');
        Schema::dropIfExists('demand_ads_txt_records');
        Schema::dropIfExists('demand_widgets');
        Schema::dropIfExists('demand_placements');
        Schema::dropIfExists('demand_sites');
        Schema::dropIfExists('demand_account_credentials');
        Schema::dropIfExists('demand_accounts');
        Schema::dropIfExists('demand_networks');
    }
};
