<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gam_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32)->index();
            $table->string('credential_type', 32);
            $table->string('driver', 24)->default('REST');
            $table->string('network_code', 64)->nullable()->index();
            $table->string('application_name')->default('Horus Media Platform');
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->boolean('dry_run_default')->default(true);
            $table->string('health_status', 24)->default('UNKNOWN')->index();
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->json('configuration')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'name'], 'gam_connections_org_name_unique');
            $table->index(['type', 'is_primary', 'is_enabled'], 'gam_connections_routing_index');
        });

        Schema::create('gam_credentials', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->unique()->constrained('gam_connections')->cascadeOnDelete();
            $table->string('credential_type', 32);
            $table->text('reference');
            $table->string('client_email_hint')->nullable();
            $table->string('oauth_client_id_hint')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'credential_type'], 'gam_credentials_org_type_index');
        });

        Schema::create('gam_networks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('network_code', 64);
            $table->string('display_name')->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('time_zone')->nullable();
            $table->boolean('is_test')->default(false);
            $table->boolean('is_current')->default(false);
            $table->json('capabilities')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'network_code'], 'gam_networks_connection_code_unique');
            $table->index(['organization_id', 'is_current'], 'gam_networks_org_current_index');
        });

        Schema::create('gam_connection_permissions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('permission_name');
            $table->string('status', 24)->default('UNKNOWN');
            $table->json('details')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'permission_name'], 'gam_conn_perm_unique');
            $table->index(['organization_id', 'status'], 'gam_conn_perm_org_status_index');
        });

        Schema::create('gam_sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 64);
            $table->string('status', 32)->default('PENDING')->index();
            $table->boolean('dry_run')->default(false);
            $table->json('counters')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'gam_connection_id', 'created_at'], 'gam_sync_runs_lookup_index');
        });

        Schema::create('gam_api_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('gam_sync_run_id')->nullable()->constrained('gam_sync_runs')->nullOnDelete();
            $table->string('operation', 100);
            $table->string('service', 100);
            $table->string('method', 100);
            $table->string('idempotency_key', 128)->nullable();
            $table->boolean('dry_run')->default(false);
            $table->string('status', 24)->default('PENDING')->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('remote_request_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_category', 32)->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'idempotency_key'], 'gam_api_idempotency_unique');
            $table->index(['organization_id', 'gam_connection_id', 'created_at'], 'gam_api_operations_lookup_index');
        });

        Schema::create('gam_remote_objects', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->string('local_object_type', 100);
            $table->string('local_object_id', 64);
            $table->string('remote_object_type', 100);
            $table->string('remote_object_id', 128);
            $table->string('idempotency_key', 128)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('remote_status', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['gam_connection_id', 'local_object_type', 'local_object_id', 'remote_object_type'], 'gam_remote_local_unique');
            $table->unique(['gam_connection_id', 'remote_object_type', 'remote_object_id'], 'gam_remote_upstream_unique');
            $table->index(['organization_id', 'gam_connection_id'], 'gam_remote_objects_lookup_index');
        });

        Schema::create('gam_sync_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('gam_sync_run_id')->nullable()->constrained('gam_sync_runs')->cascadeOnDelete();
            $table->string('level', 16)->default('INFO');
            $table->string('event', 100);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organization_id', 'gam_connection_id', 'created_at'], 'gam_sync_logs_lookup_index');
        });

        Schema::create('gam_errors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('gam_api_operation_id')->nullable()->constrained('gam_api_operations')->nullOnDelete();
            $table->foreignUlid('gam_sync_run_id')->nullable()->constrained('gam_sync_runs')->nullOnDelete();
            $table->string('category', 32)->default('UNKNOWN')->index();
            $table->string('code')->nullable();
            $table->text('message');
            $table->boolean('retryable')->default(false);
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'gam_connection_id', 'occurred_at'], 'gam_errors_lookup_index');
        });

        Schema::table('sites', function (Blueprint $table): void {
            $table->foreignUlid('gam_connection_id')->nullable()->after('serving_mode')->constrained('gam_connections')->nullOnDelete();
            $table->index(['serving_mode', 'gam_connection_id'], 'sites_serving_gam_index');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropForeign(['gam_connection_id']);
            $table->dropIndex('sites_serving_gam_index');
            $table->dropColumn('gam_connection_id');
        });

        Schema::dropIfExists('gam_errors');
        Schema::dropIfExists('gam_sync_logs');
        Schema::dropIfExists('gam_remote_objects');
        Schema::dropIfExists('gam_api_operations');
        Schema::dropIfExists('gam_sync_runs');
        Schema::dropIfExists('gam_connection_permissions');
        Schema::dropIfExists('gam_networks');
        Schema::dropIfExists('gam_credentials');
        Schema::dropIfExists('gam_connections');
    }
};
