<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->string('ads_txt_deployment_mode', 40)->default('MANUAL_COPY')->after('monetization_manager_country');
            $table->text('ads_txt_redirect_target')->nullable()->after('ads_txt_deployment_mode');
            $table->string('ads_txt_redirect_status', 32)->nullable()->after('ads_txt_redirect_target');
            $table->timestamp('ads_txt_redirect_verified_at')->nullable()->after('ads_txt_redirect_status');
        });

        Schema::create('supply_chain_origin_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('artifact', 40)->index();
            $table->text('canonical_url');
            $table->text('final_url')->nullable();
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->string('payload_sha256', 64)->nullable()->index();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supply_chain_origin_checks');
        Schema::table('site_serving_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'ads_txt_deployment_mode', 'ads_txt_redirect_target',
                'ads_txt_redirect_status', 'ads_txt_redirect_verified_at',
            ]);
        });
    }
};
