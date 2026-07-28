<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loader_releases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version', 40)->unique();
            $table->string('source_path');
            $table->string('minified_path');
            $table->string('checksum', 64);
            $table->boolean('is_active')->default(false)->index();
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tag_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version', 40)->unique();
            $table->string('gpt_url');
            $table->json('settings')->nullable();
            $table->string('checksum', 64);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_configs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('loader_release_id')->nullable()->constrained('loader_releases')->nullOnDelete();
            $table->foreignUlid('tag_version_id')->nullable()->constrained('tag_versions')->nullOnDelete();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->boolean('immediate_pause')->default(false)->index();
            $table->boolean('debug_enabled')->default(false);
            $table->boolean('house_ad_testing')->default(false);
            $table->boolean('single_request_mode')->default(true);
            $table->unsignedInteger('cache_ttl_seconds')->default(60);
            $table->unsignedInteger('preview_version')->nullable();
            $table->unsignedInteger('test_version')->nullable();
            $table->unsignedInteger('production_version')->nullable();
            $table->json('page_targeting')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status'], 'site_configs_org_status_index');
        });

        Schema::create('ad_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 120);
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('sync_status', 24)->default('PENDING')->index();
            $table->string('last_sync_hash', 64)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['site_id', 'code'], 'ad_units_site_code_unique');
            $table->index(['organization_id', 'site_id', 'is_enabled'], 'ad_units_lookup_index');
        });

        Schema::create('ad_unit_sizes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ad_unit_id')->constrained('ad_units')->cascadeOnDelete();
            $table->string('size_type', 20)->default('FIXED');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['ad_unit_id', 'size_type', 'width', 'height'], 'ad_unit_sizes_unique');
        });

        Schema::create('placements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('ad_unit_id')->nullable()->constrained('ad_units')->nullOnDelete();
            $table->string('name');
            $table->string('code', 120);
            $table->string('type', 24)->index();
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->boolean('lazy_load_enabled')->default(true);
            $table->unsignedSmallInteger('lazy_fetch_margin_percent')->default(500);
            $table->unsignedSmallInteger('lazy_render_margin_percent')->default(200);
            $table->decimal('lazy_mobile_scaling', 5, 2)->default(2);
            $table->boolean('refresh_enabled')->default(false);
            $table->unsignedSmallInteger('refresh_interval_seconds')->nullable();
            $table->unsignedSmallInteger('refresh_limit')->nullable();
            $table->boolean('collapse_empty_div')->default(true);
            $table->boolean('safeframe_enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['site_id', 'code'], 'placements_site_code_unique');
            $table->index(['organization_id', 'site_id', 'status'], 'placements_lookup_index');
        });

        Schema::create('placement_sizes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('placement_id')->constrained('placements')->cascadeOnDelete();
            $table->string('size_type', 20)->default('FIXED');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('device', 20)->default('ALL');
            $table->unsignedSmallInteger('min_viewport_width')->default(0);
            $table->unsignedSmallInteger('min_viewport_height')->default(0);
            $table->unsignedSmallInteger('max_viewport_width')->nullable();
            $table->unsignedSmallInteger('max_viewport_height')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['placement_id', 'device', 'priority'], 'placement_sizes_mapping_index');
        });

        Schema::create('placement_targeting', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('placement_id')->nullable()->constrained('placements')->cascadeOnDelete();
            $table->string('scope', 20)->default('PLACEMENT');
            $table->string('environment', 20)->nullable();
            $table->string('targeting_key', 120);
            $table->json('targeting_values');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['site_id', 'placement_id', 'scope'], 'placement_targeting_lookup_index');
        });

        Schema::create('site_layout_profiles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('source_site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('snapshot');
            $table->boolean('is_default')->default(false);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'site_id'], 'site_layout_profiles_lookup_index');
        });

        Schema::create('config_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignUlid('site_config_id')->constrained('site_configs')->cascadeOnDelete();
            $table->foreignUlid('source_version_id')->nullable()->constrained('config_versions')->nullOnDelete();
            $table->string('environment', 20)->index();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('PUBLISHED')->index();
            $table->json('payload');
            $table->string('checksum', 64);
            $table->string('file_path');
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'environment', 'version'], 'config_versions_site_env_version_unique');
            $table->index(['organization_id', 'site_id', 'environment'], 'config_versions_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_versions');
        Schema::dropIfExists('site_layout_profiles');
        Schema::dropIfExists('placement_targeting');
        Schema::dropIfExists('placement_sizes');
        Schema::dropIfExists('placements');
        Schema::dropIfExists('ad_unit_sizes');
        Schema::dropIfExists('ad_units');
        Schema::dropIfExists('site_configs');
        Schema::dropIfExists('tag_versions');
        Schema::dropIfExists('loader_releases');
    }
};
