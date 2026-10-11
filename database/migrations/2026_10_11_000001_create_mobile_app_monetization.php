<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_apps', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('publisher_id')->constrained();
            $t->string('public_id', 40)->unique();
            $t->string('name');
            $t->string('platform', 10);
            $t->string('store', 24);
            $t->string('store_url', 1000);
            $t->string('store_app_id');
            $t->string('developer_domain');
            $t->unique(['platform', 'store_app_id']);
            $t->string('status', 20)->default('PENDING');
            $t->text('review_notes')->nullable();
            $t->timestamp('store_reviewed_at')->nullable();
            $t->foreignUlid('reviewed_by')->nullable()->constrained('users');
            $t->foreignUlid('gam_connection_id')->nullable()->constrained();
            $t->string('sdk_app_id', 80)->nullable();
            $t->json('app_ads_evidence')->nullable();
            $t->timestamps();
        });
        Schema::create('mobile_app_ad_units', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('mobile_app_id')->constrained();
            $t->string('name');
            $t->string('format', 30);
            $t->string('ad_unit_path', 255);
            $t->boolean('is_enabled')->default(true);
            $t->timestamps();
            $t->unique(['mobile_app_id', 'ad_unit_path']);
        });
        Schema::create('mobile_app_report_bindings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained();
            $t->foreignUlid('mobile_app_id')->constrained();
            $t->foreignUlid('gam_connection_id')->constrained();
            $t->foreignUlid('report_source_connection_id')->constrained();
            $t->string('network_code', 80);
            $t->string('ad_unit_id', 80);
            $t->string('ad_unit_name');
            $t->string('ad_unit_code');
            $t->string('active_unit_key', 170)->nullable()->unique();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users');
            $t->timestamps();
        });
        Schema::table('seller_declarations', function (Blueprint $t) {
            $t->foreignUlid('mobile_app_id')->nullable()->unique()->constrained();
        });
        Schema::table('report_dimensions', fn (Blueprint $t) => $t->foreignUlid('mobile_app_id')->nullable()->constrained());
    }

    public function down(): void
    {
        Schema::table('report_dimensions', fn (Blueprint $t) => $t->dropConstrainedForeignId('mobile_app_id'));
        Schema::table('seller_declarations', function (Blueprint $t) {
            $t->dropForeign(['mobile_app_id']);
            $t->dropUnique(['mobile_app_id']);
            $t->dropColumn('mobile_app_id');
        });
        Schema::dropIfExists('mobile_app_report_bindings');
        Schema::dropIfExists('mobile_app_ad_units');
        Schema::dropIfExists('mobile_apps');
    }
};
