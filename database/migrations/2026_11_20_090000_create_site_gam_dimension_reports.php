<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nonfinancial sidecar: exact-site Google Ad Exchange delivery broken down
     * by country or device category. daily_reports remains the only ledger.
     */
    public function up(): void
    {
        Schema::create('site_gam_dimension_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_gam_report_binding_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->date('report_date');
            $table->string('dimension_key', 64);
            $table->string('dimension_name', 128)->nullable();
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
            $table->unique(['report_source_connection_id', 'kind', 'report_date', 'dimension_key'], 'site_dimension_connection_kind_day_key');
            $table->index(['site_id', 'kind', 'report_date'], 'site_dimension_site_kind_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_gam_dimension_reports');
    }
};
