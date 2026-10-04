<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_gam_video_unfilled_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained(indexName: 'video_unfilled_source_fk')->restrictOnDelete();
            $table->foreignUlid('site_gam_video_report_binding_id')->constrained(indexName: 'video_unfilled_binding_fk')->restrictOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained()->restrictOnDelete();
            $table->string('network_code', 32);
            $table->string('ad_unit_id', 32);
            $table->date('report_date');
            $table->string('timezone', 64);
            $table->unsignedBigInteger('unfilled_impressions');
            $table->string('google_report_job_id', 32);
            $table->timestamp('reported_at');
            $table->timestamps();
            $table->unique(['report_source_connection_id', 'report_date'], 'site_video_unfilled_connection_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_gam_video_unfilled_reports');
    }
};
