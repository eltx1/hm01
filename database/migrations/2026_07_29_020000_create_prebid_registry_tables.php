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
            $table->string('version', 40)->unique();
            $table->string('name');
            $table->string('source_ref')->nullable();
            $table->string('source_commit', 64)->nullable();
            $table->string('source_url')->nullable();
            $table->string('asset_path');
            $table->string('minified_path');
            $table->string('manifest_path')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->json('modules');
            $table->json('adapters');
            $table->string('status', 24)->default('READY')->index();
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
        });

        Schema::create('prebid_adapters', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('bidder_code', 80)->unique();
            $table->string('adapter_name');
            $table->json('required_public_parameters');
            $table->json('optional_public_parameters')->nullable();
            $table->json('supported_media_types');
            $table->json('supported_sizes')->nullable();
            $table->string('documentation_url');
            $table->boolean('enabled')->default(true)->index();
            $table->timestamp('deprecated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('prebid_bidders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('prebid_adapter_id')->constrained('prebid_adapters')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 100);
            $table->boolean('enabled')->default(true)->index();
            $table->json('default_public_parameters')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
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
            $table->softDeletes();
            $table->unique(['organization_id', 'prebid_bidder_id', 'name'], 'bidder_accounts_org_bidder_name_unique');
        });

        Schema::create('bidder_site_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_account_id')->constrained('bidder_accounts')->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->cascadeOnDelete();
            $table->string('gam_connection_key', 32)->default('DEFAULT');
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedSmallInteger('sequence')->default(100);
            $table->json('public_parameters')->nullable();
            $table->timestamps();
            $table->unique(['bidder_account_id', 'site_id', 'gam_connection_key'], 'bidder_site_scope_unique');
            $table->index(['organization_id', 'site_id', 'gam_connection_id', 'enabled'], 'bidder_site_lookup_index');
        });

        Schema::create('bidder_placement_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('bidder_account_id')->constrained('bidder_accounts')->cascadeOnDelete();
            $table->foreignUlid('placement_id')->constrained('placements')->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->cascadeOnDelete();
            $table->string('gam_connection_key', 32)->default('DEFAULT');
            $table->boolean('enabled')->default(true)->index();
            $table->json('public_parameters')->nullable();
            $table->timestamps();
            $table->unique(['bidder_account_id', 'placement_id', 'gam_connection_key'], 'bidder_placement_scope_unique');
            $table->index(['organization_id', 'placement_id', 'gam_connection_id', 'enabled'], 'bidder_placement_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bidder_placement_mappings');
        Schema::dropIfExists('bidder_site_mappings');
        Schema::dropIfExists('bidder_accounts');
        Schema::dropIfExists('prebid_bidders');
        Schema::dropIfExists('prebid_adapters');
        Schema::dropIfExists('prebid_builds');
    }
};
