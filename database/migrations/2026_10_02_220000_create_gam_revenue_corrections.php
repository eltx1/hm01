<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gam_revenue_corrections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('site_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('source_connection_id')->constrained('report_source_connections')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 16);
            $table->json('context');
            $table->json('snapshot');
            $table->string('query_hash', 64);
            $table->json('job');
            $table->json('proposal')->nullable();
            $table->string('digest', 64)->nullable();
            $table->string('error_code', 96)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('applied_at')->nullable();
            $table->json('receipt')->nullable();
            $table->timestamps();
            $table->index(['actor_id', 'expires_at']);
        });
        Schema::create('gam_revenue_correction_receipts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('correction_id')->unique()->constrained('gam_revenue_corrections')->restrictOnDelete();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('report_import_job_id')->constrained('report_import_jobs')->restrictOnDelete();
            $table->foreignUlid('reconciliation_run_id')->constrained('reconciliation_runs')->restrictOnDelete();
            $table->string('digest', 64);
            $table->string('query_hash', 64);
            $table->string('before_hash', 64);
            $table->string('after_hash', 64);
            $table->text('reason');
            $table->json('context');
            $table->json('before');
            $table->json('after');
            $table->timestamp('applied_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // An applied receipt is financial recovery evidence, never a disposable
        // deployment artifact. A populated installation needs an explicit plan.
        if (\Illuminate\Support\Facades\DB::table('gam_revenue_correction_receipts')->exists()) {
            throw new RuntimeException('Applied correction receipts must be preserved.');
        }
        Schema::dropIfExists('gam_revenue_correction_receipts');
        Schema::dropIfExists('gam_revenue_corrections');
    }
};
