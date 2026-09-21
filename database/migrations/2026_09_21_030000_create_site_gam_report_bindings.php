<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_gam_report_bindings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('gam_connection_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('report_source_connection_id')->unique()->constrained()->restrictOnDelete();
            // Nullable keys reserve one current source per site and one owner
            // per network/ad-unit, including duplicate credentials for a network.
            $table->ulid('active_site_id')->nullable()->unique();
            $table->string('active_unit_key', 96)->nullable()->unique();
            $table->string('network_code', 32);
            $table->string('ad_unit_id', 32);
            $table->string('ad_unit_name');
            $table->string('ad_unit_code');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['site_id', 'starts_on', 'ends_on'], 'site_gam_reporting_dates');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_gam_report_bindings');
    }
};
