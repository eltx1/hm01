<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prebid_price_buckets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 80);
            $table->string('currency_code', 3)->default('USD');
            $table->string('granularity', 20)->default('CUSTOM');
            $table->json('ranges');
            $table->boolean('is_default')->default(false)->index();
            $table->boolean('enabled')->default(true)->index();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'prebid_price_buckets_org_code_unique');
        });

        Schema::create('prebid_settings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_build_id')->nullable()->constrained('prebid_builds')->nullOnDelete();
            $table->foreignUlid('prebid_price_bucket_id')->nullable()->constrained('prebid_price_buckets')->nullOnDelete();
            $table->boolean('enabled')->default(false)->index();
            $table->unsignedSmallInteger('auction_timeout_ms')->default(1200);
            $table->string('price_granularity', 20)->default('custom');
            $table->string('currency_code', 3)->default('USD');
            $table->string('bidder_sequence', 20)->default('random');
            $table->json('consent_behavior')->nullable();
            $table->json('lazy_loading')->nullable();
            $table->json('refresh_behavior')->nullable();
            $table->boolean('timeout_reporting')->default(false);
            $table->boolean('gam_fallback')->default(true);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['site_id', 'gam_connection_id'], 'prebid_settings_site_gam_unique');
            $table->index(['organization_id', 'gam_connection_id', 'enabled'], 'prebid_settings_lookup_index');
        });

        Schema::create('prebid_gam_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained('gam_connections')->cascadeOnDelete();
            $table->foreignUlid('prebid_price_bucket_id')->constrained('prebid_price_buckets')->restrictOnDelete();
            $table->string('name');
            $table->boolean('enabled')->default(true)->index();
            $table->string('advertiser_name');
            $table->string('order_prefix');
            $table->string('line_item_prefix');
            $table->string('creative_prefix');
            $table->json('targeting_keys');
            $table->json('targeting_values')->nullable();
            $table->json('creative_sizes');
            $table->unsignedSmallInteger('max_line_items_per_order')->default(450);
            $table->unsignedSmallInteger('priority')->default(12);
            $table->string('currency_code', 3)->default('USD');
            $table->longText('universal_creative_snippet');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('gam_connection_id', 'prebid_gam_templates_connection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prebid_gam_templates');
        Schema::dropIfExists('prebid_settings');
        Schema::dropIfExists('prebid_price_buckets');
    }
};
