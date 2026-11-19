<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nonfinancial sidecar: Google Ad Exchange delivery for the exact
     * registered website, broken down by Google's inventory format. Ledger
     * rows (daily_reports) remain the only financial source of truth.
     */
    public function up(): void
    {
        Schema::create('site_gam_ad_format_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_gam_report_binding_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained()->restrictOnDelete();
            $table->string('network_code', 32);
            $table->string('ad_unit_id', 32);
            $table->string('hostname', 255);
            $table->date('report_date');
            $table->string('timezone', 64);
            $table->string('ad_format', 64);
            $table->string('google_format_id', 32)->nullable();
            $table->string('google_format_name', 128)->nullable();
            $table->unsignedBigInteger('ad_requests')->nullable();
            $table->unsignedBigInteger('matched_requests')->nullable();
            $table->unsignedBigInteger('impressions');
            $table->unsignedBigInteger('clicks');
            $table->unsignedBigInteger('active_view_viewable_impressions')->nullable();
            $table->unsignedBigInteger('active_view_measurable_impressions')->nullable();
            $table->bigInteger('revenue_micros');
            $table->string('google_report_job_id', 32);
            $table->timestamp('reported_at');
            $table->timestamps();
            $table->unique(['report_source_connection_id', 'report_date', 'ad_format'], 'site_ad_format_connection_day_format');
            $table->index(['site_id', 'report_date'], 'site_ad_format_site_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_gam_ad_format_reports');
    }
};
