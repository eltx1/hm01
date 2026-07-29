<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->string('connector_class')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('report_source_connections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('report_source_id')->constrained('report_sources')->restrictOnDelete();
            $table->string('name');
            $table->string('connection_type', 80);
            $table->string('connection_id', 64)->nullable();
            $table->string('account_identifier')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 24)->default('ACTIVE')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->json('configuration')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_successful_import_at')->nullable();
            $table->timestamp('last_finalized_import_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['report_source_id', 'connection_type', 'connection_id'], 'report_source_connections_unique');
            $table->index(['status', 'is_enabled'], 'report_source_connections_schedule');
        });

        Schema::create('financial_periods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period_key', 7);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('currency', 3)->default('USD');
            $table->string('status', 24)->default('OPEN')->index();
            $table->timestamp('closing_started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('snapshot_hash', 64)->nullable();
            $table->json('totals')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'period_key', 'currency'], 'financial_periods_unique');
            $table->index(['status', 'starts_on', 'ends_on'], 'financial_periods_lookup');
        });

        Schema::create('report_import_jobs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained('report_source_connections')->cascadeOnDelete();
            $table->foreignUlid('financial_period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            $table->string('import_type', 32);
            $table->string('granularity', 16);
            $table->string('finality', 16);
            $table->string('status', 32)->default('PENDING')->index();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('external_report_id', 255)->nullable();
            $table->string('idempotency_key', 64)->unique();
            $table->string('checksum', 64)->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->json('source_totals')->nullable();
            $table->json('normalized_totals')->nullable();
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['report_source_connection_id', 'period_start', 'period_end'], 'report_import_jobs_period');
            $table->index(['status', 'next_retry_at'], 'report_import_jobs_retry');
        });

        Schema::create('report_import_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('report_import_job_id')->constrained('report_import_jobs')->cascadeOnDelete();
            $table->string('disk', 40)->default('local');
            $table->string('path', 1000);
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['report_import_job_id', 'checksum'], 'report_import_files_unique');
        });

        Schema::create('report_dimensions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('placement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('gam_connection_id')->nullable()->constrained('gam_connections')->nullOnDelete();
            $table->foreignUlid('demand_network_id')->nullable()->constrained('demand_networks')->nullOnDelete();
            $table->string('bidder_id', 64)->nullable();
            $table->foreignUlid('advertiser_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('country_code', 2)->nullable();
            $table->string('device', 80)->nullable();
            $table->string('browser', 120)->nullable();
            $table->string('operating_system', 120)->nullable();
            $table->string('ad_size', 32)->nullable();
            $table->json('external_dimensions')->nullable();
            $table->string('dimension_hash', 64)->unique();
            $table->timestamps();
            $table->index(['publisher_id', 'site_id', 'placement_id'], 'report_dimensions_publisher');
            $table->index(['advertiser_id', 'campaign_id'], 'report_dimensions_advertiser');
        });

        Schema::create('revenue_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('scope_type', 32)->index();
            $table->string('scope_id', 64)->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['scope_type', 'scope_id', 'is_active', 'effective_from'], 'revenue_rules_resolution');
        });

        Schema::create('revenue_rule_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('revenue_rule_id')->constrained('revenue_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('publisher_share_bp');
            $table->unsignedInteger('horus_share_bp');
            $table->unsignedInteger('mcm_partner_share_bp')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('reason')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['revenue_rule_id', 'version'], 'revenue_rule_versions_unique');
            $table->index(['effective_from', 'effective_to'], 'revenue_rule_versions_dates');
        });

        Schema::table('revenue_rules', function (Blueprint $table): void {
            $table->foreignUlid('current_version_id')->nullable()->after('priority')
                ->constrained('revenue_rule_versions')->nullOnDelete();
        });

        $metricColumns = function (Blueprint $table): void {
            $table->unsignedBigInteger('ad_requests')->default(0);
            $table->unsignedBigInteger('matched_requests')->default(0);
            $table->unsignedBigInteger('unfilled_requests')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('fill_rate_bp')->default(0);
            $table->unsignedInteger('ctr_bp')->default(0);
            $table->unsignedInteger('viewability_bp')->nullable();
            $table->bigInteger('gross_revenue_minor')->default(0);
            $table->bigInteger('demand_partner_deductions_minor')->default(0);
            $table->bigInteger('invalid_traffic_adjustments_minor')->default(0);
            $table->bigInteger('other_adjustments_minor')->default(0);
            $table->bigInteger('net_revenue_minor')->default(0);
            $table->bigInteger('publisher_earnings_minor')->default(0);
            $table->bigInteger('horus_earnings_minor')->default(0);
            $table->bigInteger('mcm_partner_earnings_minor')->default(0);
            $table->unsignedBigInteger('ecpm_micros')->default(0);
            $table->unsignedBigInteger('cpc_micros')->default(0);
            $table->unsignedBigInteger('video_starts')->default(0);
            $table->unsignedBigInteger('completed_views')->default(0);
        };

        Schema::create('hourly_reports', function (Blueprint $table) use ($metricColumns): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained('report_source_connections')->cascadeOnDelete();
            $table->foreignUlid('report_import_job_id')->constrained('report_import_jobs')->cascadeOnDelete();
            $table->foreignUlid('financial_period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            $table->foreignUlid('report_dimension_id')->constrained('report_dimensions')->cascadeOnDelete();
            $table->date('report_date');
            $table->unsignedTinyInteger('report_hour');
            $table->string('finality', 16)->default('ESTIMATED')->index();
            $table->string('currency', 3)->default('USD');
            $table->foreignUlid('revenue_rule_version_id')->nullable()->constrained('revenue_rule_versions')->nullOnDelete();
            $metricColumns($table);
            $table->string('source_row_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['report_source_connection_id', 'report_date', 'report_hour', 'report_dimension_id'], 'hourly_reports_unique');
            $table->index(['organization_id', 'report_date'], 'hourly_reports_org_date');
        });

        Schema::create('daily_reports', function (Blueprint $table) use ($metricColumns): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained('report_source_connections')->cascadeOnDelete();
            $table->foreignUlid('report_import_job_id')->constrained('report_import_jobs')->cascadeOnDelete();
            $table->foreignUlid('financial_period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            $table->foreignUlid('report_dimension_id')->constrained('report_dimensions')->cascadeOnDelete();
            $table->date('report_date');
            $table->string('finality', 16)->default('FINALIZED')->index();
            $table->string('currency', 3)->default('USD');
            $table->foreignUlid('revenue_rule_version_id')->nullable()->constrained('revenue_rule_versions')->nullOnDelete();
            $metricColumns($table);
            $table->string('source_row_hash', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['report_source_connection_id', 'report_date', 'report_dimension_id'], 'daily_reports_unique');
            $table->index(['organization_id', 'report_date'], 'daily_reports_org_date');
            $table->index(['financial_period_id', 'finality'], 'daily_reports_period_finality');
        });

        Schema::create('monthly_reports', function (Blueprint $table) use ($metricColumns): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('financial_period_id')->constrained('financial_periods')->cascadeOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained('report_source_connections')->cascadeOnDelete();
            $table->foreignUlid('report_dimension_id')->constrained('report_dimensions')->cascadeOnDelete();
            $table->string('period_key', 7);
            $table->string('currency', 3)->default('USD');
            $table->foreignUlid('revenue_rule_version_id')->nullable()->constrained('revenue_rule_versions')->nullOnDelete();
            $metricColumns($table);
            $table->string('snapshot_hash', 64);
            $table->timestamps();
            $table->unique(['financial_period_id', 'report_source_connection_id', 'report_dimension_id'], 'monthly_reports_unique');
            $table->index(['organization_id', 'period_key'], 'monthly_reports_org_period');
        });

        Schema::create('revenue_adjustments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('financial_period_id')->nullable()->constrained('financial_periods')->nullOnDelete();
            $table->foreignUlid('report_source_connection_id')->nullable()->constrained('report_source_connections')->nullOnDelete();
            $table->foreignUlid('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_on');
            $table->string('type', 48);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 24)->default('PENDING')->index();
            $table->text('reason');
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
            $table->index(['effective_on', 'status', 'currency'], 'revenue_adjustments_effective');
        });

        Schema::create('reconciliation_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained('report_source_connections')->cascadeOnDelete();
            $table->foreignUlid('report_import_job_id')->nullable()->constrained('report_import_jobs')->nullOnDelete();
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->string('granularity', 16);
            $table->string('status', 24)->default('PENDING')->index();
            $table->json('source_totals')->nullable();
            $table->json('stored_totals')->nullable();
            $table->json('differences')->nullable();
            $table->unsignedInteger('discrepancy_basis_points')->default(0);
            $table->json('warnings')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['report_source_connection_id', 'period_start', 'period_end'], 'reconciliation_runs_period');
        });

        Schema::create('publisher_statements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('financial_period_id')->constrained('financial_periods')->restrictOnDelete();
            $table->string('statement_number', 64)->unique();
            $table->string('status', 32)->default('DRAFT')->index();
            $table->string('currency', 3);
            $table->bigInteger('opening_balance_minor')->default(0);
            $table->bigInteger('gross_revenue_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('net_revenue_minor')->default(0);
            $table->bigInteger('publisher_earnings_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('balance_due_minor')->default(0);
            $table->bigInteger('carry_forward_minor')->default(0);
            $table->unsignedBigInteger('payment_threshold_minor')->default(0);
            $table->foreignUlid('revenue_rule_version_id')->nullable()->constrained('revenue_rule_versions')->nullOnDelete();
            $table->json('line_items');
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->timestamp('finalized_at')->nullable();
            $table->foreignUlid('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('publisher_invoice_number', 128)->nullable();
            $table->string('publisher_invoice_path', 1000)->nullable();
            $table->timestamp('publisher_invoice_uploaded_at')->nullable();
            $table->foreignUlid('publisher_invoice_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['publisher_id', 'financial_period_id', 'currency'], 'publisher_statements_unique');
            $table->index(['publisher_id', 'status'], 'publisher_statements_status');
        });

        Schema::create('publisher_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publisher_statement_id')->constrained('publisher_statements')->restrictOnDelete();
            $table->string('payment_number', 64)->unique();
            $table->string('status', 32)->default('PENDING')->index();
            $table->string('currency', 3);
            $table->bigInteger('amount_minor');
            $table->string('payment_method', 48)->nullable();
            $table->string('horus_payment_reference', 255)->nullable()->index();
            $table->date('scheduled_on')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['publisher_id', 'status'], 'publisher_payments_lookup');
        });

        Schema::create('advertiser_reports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('advertiser_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('report_source_connection_id')->constrained('report_source_connections')->cascadeOnDelete();
            $table->foreignUlid('report_import_job_id')->nullable()->constrained('report_import_jobs')->nullOnDelete();
            $table->foreignUlid('report_dimension_id')->nullable()->constrained('report_dimensions')->nullOnDelete();
            $table->date('report_date');
            $table->string('finality', 16)->default('FINALIZED')->index();
            $table->string('currency', 3);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('ctr_bp')->default(0);
            $table->unsignedBigInteger('video_starts')->default(0);
            $table->unsignedBigInteger('completed_views')->default(0);
            $table->bigInteger('spend_minor')->default(0);
            $table->bigInteger('remaining_budget_minor')->default(0);
            $table->string('source_row_hash', 64);
            $table->timestamps();
            $table->unique(['campaign_id', 'report_source_connection_id', 'report_date', 'report_dimension_id'], 'advertiser_reports_unique');
            $table->index(['advertiser_id', 'report_date'], 'advertiser_reports_advertiser_date');
        });

        Schema::create('currency_rates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('base_currency', 3);
            $table->string('quote_currency', 3);
            $table->date('rate_date');
            $table->decimal('rate', 20, 10);
            $table->string('source', 64)->default('MANUAL');
            $table->string('checksum', 64);
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['base_currency', 'quote_currency', 'rate_date'], 'currency_rates_unique');
        });

        Schema::create('report_errors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('report_source_connection_id')->nullable()->constrained('report_source_connections')->nullOnDelete();
            $table->foreignUlid('report_import_job_id')->nullable()->constrained('report_import_jobs')->nullOnDelete();
            $table->string('category', 64)->index();
            $table->string('code', 120)->nullable();
            $table->text('message');
            $table->boolean('retryable')->default(false)->index();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('advertiser_invoices', function (Blueprint $table): void {
            $table->foreignUlid('financial_period_id')->nullable()->after('campaign_id')
                ->constrained('financial_periods')->nullOnDelete();
            $table->bigInteger('amount_paid_minor')->default(0)->after('total_minor');
            $table->bigInteger('balance_due_minor')->default(0)->after('amount_paid_minor');
            $table->string('payment_reference')->nullable()->after('paid_at');
            $table->json('report_snapshot')->nullable()->after('line_items');
            $table->string('snapshot_hash', 64)->nullable()->after('report_snapshot');
        });

        DB::table('advertiser_invoices')->update([
            'balance_due_minor' => DB::raw('total_minor - amount_paid_minor'),
        ]);
    }

    public function down(): void
    {
        Schema::table('advertiser_invoices', function (Blueprint $table): void {
            $table->dropForeign(['financial_period_id']);
            $table->dropColumn([
                'financial_period_id', 'amount_paid_minor', 'balance_due_minor',
                'payment_reference', 'report_snapshot', 'snapshot_hash',
            ]);
        });

        Schema::dropIfExists('report_errors');
        Schema::dropIfExists('currency_rates');
        Schema::dropIfExists('advertiser_reports');
        Schema::dropIfExists('publisher_payments');
        Schema::dropIfExists('publisher_statements');
        Schema::dropIfExists('reconciliation_runs');
        Schema::dropIfExists('revenue_adjustments');
        Schema::dropIfExists('monthly_reports');
        Schema::dropIfExists('daily_reports');
        Schema::dropIfExists('hourly_reports');

        Schema::table('revenue_rules', function (Blueprint $table): void {
            $table->dropForeign(['current_version_id']);
            $table->dropColumn('current_version_id');
        });
        Schema::dropIfExists('revenue_rule_versions');
        Schema::dropIfExists('revenue_rules');
        Schema::dropIfExists('report_dimensions');
        Schema::dropIfExists('report_import_files');
        Schema::dropIfExists('report_import_jobs');
        Schema::dropIfExists('financial_periods');
        Schema::dropIfExists('report_source_connections');
        Schema::dropIfExists('report_sources');
    }
};
