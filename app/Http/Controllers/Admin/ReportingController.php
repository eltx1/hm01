<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdjustmentStatus;
use App\Enums\FinancialPeriodStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\ReportConnectionStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\RevenueRuleScope;
use App\Http\Controllers\Controller;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\PublisherPayment;
use App\Models\PublisherStatement;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueAdjustment;
use App\Models\RevenueRule;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\PublisherPaymentService;
use App\Services\Reporting\PublisherStatementService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\RevenueAdjustmentService;
use App\Services\Reporting\RevenueRuleService;
use App\Services\Reporting\UnifiedReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReportingController extends Controller
{
    public function index(Request $request, UnifiedReportService $reports): View
    {
        $from = $request->date('from') ?: now()->startOfMonth();
        $to = $request->date('to') ?: now();

        return view('admin.reporting.index', [
            'summary' => $reports->adminSummary($from, $to, $request->string('currency')->value() ?: null),
            'sources' => ReportSource::query()->withCount('connections')->orderByDesc('is_primary')->orderBy('name')->get(),
            'connections' => ReportSourceConnection::withoutGlobalScopes()->with('source')->latest()->limit(100)->get(),
            'imports' => ReportImportJob::withoutGlobalScopes()->with('connection.source')->latest()->limit(100)->get(),
            'periods' => FinancialPeriod::query()->latest('starts_on')->limit(24)->get(),
            'rules' => RevenueRule::withoutGlobalScopes()->with('currentVersion')->orderByDesc('priority')->get(),
            'adjustments' => RevenueAdjustment::withoutGlobalScopes()->with(['period', 'publisher'])->latest()->limit(100)->get(),
            'statements' => PublisherStatement::withoutGlobalScopes()->with(['publisher', 'period'])->latest()->limit(100)->get(),
            'payments' => PublisherPayment::withoutGlobalScopes()->with(['publisher', 'statement'])->latest()->limit(100)->get(),
            'publishers' => Publisher::withoutGlobalScopes()->orderBy('display_name')->get(),
            'granularities' => ReportGranularity::cases(),
            'finalities' => ReportFinality::cases(),
            'ruleScopes' => RevenueRuleScope::cases(),
        ]);
    }

    public function storeConnection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'report_source_id' => ['required', 'ulid', 'exists:report_sources,id'],
            'organization_id' => ['nullable', 'ulid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'connection_type' => ['required', 'string', 'max:80'],
            'connection_id' => ['nullable', 'string', 'max:64'],
            'account_identifier' => ['nullable', 'string', 'max:255'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'configuration_json' => ['nullable', 'json'],
        ]);

        ReportSourceConnection::withoutGlobalScopes()->updateOrCreate(
            [
                'report_source_id' => $data['report_source_id'],
                'connection_type' => $data['connection_type'],
                'connection_id' => $data['connection_id'] ?? null,
            ],
            [
                'organization_id' => $data['organization_id'] ?? $request->user()->organization_id,
                'name' => $data['name'],
                'account_identifier' => $data['account_identifier'] ?? null,
                'currency' => strtoupper($data['currency']),
                'timezone' => $data['timezone'],
                'status' => ReportConnectionStatus::Active,
                'is_enabled' => true,
                'configuration' => isset($data['configuration_json']) ? json_decode($data['configuration_json'], true) : null,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ],
        );

        return back()->with('status', 'Report source connection saved.');
    }

    public function connectionStatus(Request $request, ReportSourceConnection $reportSourceConnection): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(ReportConnectionStatus::class)],
            'is_enabled' => ['required', 'boolean'],
        ]);
        $reportSourceConnection->update($data + ['updated_by' => $request->user()->id]);

        return back()->with('status', 'Report source status updated.');
    }

    public function importCsv(Request $request, ReportSourceConnection $reportSourceConnection, ReportImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'report' => ['required', 'file', 'mimes:csv,txt', 'max:25600'],
            'granularity' => ['required', Rule::enum(ReportGranularity::class)],
            'finality' => ['required', Rule::enum(ReportFinality::class)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $job = $imports->importCsv(
            $reportSourceConnection,
            $data['report'],
            ReportGranularity::from($data['granularity']),
            ReportFinality::from($data['finality']),
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
            $request->user(),
        );

        return back()->with($job->status->value === 'COMPLETED' ? 'status' : 'error', "Import {$job->status->value}: {$job->row_count} rows.");
    }

    public function manualImport(Request $request, ReportSourceConnection $reportSourceConnection, ReportImportService $imports): RedirectResponse
    {
        $data = $request->validate([
            'rows_json' => ['required', 'json'],
            'granularity' => ['required', Rule::enum(ReportGranularity::class)],
            'finality' => ['required', Rule::enum(ReportFinality::class)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'external_report_id' => ['nullable', 'string', 'max:255'],
        ]);
        $rows = json_decode($data['rows_json'], true, 512, JSON_THROW_ON_ERROR);
        $job = $imports->importRows(
            $reportSourceConnection,
            is_array($rows) ? $rows : [],
            ReportGranularity::from($data['granularity']),
            ReportFinality::from($data['finality']),
            CarbonImmutable::parse($data['from']),
            CarbonImmutable::parse($data['to']),
            $request->user(),
            $data['external_report_id'] ?? null,
            importType: 'MANUAL',
        );

        return back()->with($job->status->value === 'COMPLETED' ? 'status' : 'error', "Manual import {$job->status->value}.");
    }

    public function retry(Request $request, ReportImportJob $reportImportJob, ReportImportService $imports): RedirectResponse
    {
        $job = $imports->retry($reportImportJob, $request->user());
        return back()->with($job->status->value === 'COMPLETED' ? 'status' : 'error', "Retry {$job->status->value}.");
    }

    public function storeRule(Request $request, RevenueRuleService $rules): RedirectResponse
    {
        $data = $this->ruleData($request);
        $rules->createRule($data, $request->user());
        return back()->with('status', 'Revenue-share rule created with version 1.');
    }

    public function versionRule(Request $request, RevenueRule $revenueRule, RevenueRuleService $rules): RedirectResponse
    {
        $data = $this->ruleData($request, false);
        $rules->changeRule($revenueRule, $data, $request->user());
        return back()->with('status', 'A new immutable revenue-share version was created.');
    }

    public function storeAdjustment(Request $request, RevenueAdjustmentService $adjustments): RedirectResponse
    {
        $data = $request->validate([
            'financial_period_id' => ['required', 'ulid', 'exists:financial_periods,id'],
            'publisher_id' => ['nullable', 'ulid', 'exists:publishers,id'],
            'site_id' => ['nullable', 'ulid', 'exists:sites,id'],
            'campaign_id' => ['nullable', 'ulid', 'exists:campaigns,id'],
            'report_source_connection_id' => ['nullable', 'ulid', 'exists:report_source_connections,id'],
            'effective_on' => ['required', 'date'],
            'type' => ['required', Rule::in(['DEMAND_PARTNER_DEDUCTION', 'INVALID_TRAFFIC', 'OTHER_APPROVED'])],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'reason' => ['required', 'string', 'max:10000'],
        ]);
        $adjustments->create($data, $request->user());
        return back()->with('status', 'Adjustment created and awaits separate approval.');
    }

    public function approveAdjustment(Request $request, RevenueAdjustment $revenueAdjustment, RevenueAdjustmentService $adjustments): RedirectResponse
    {
        $adjustments->approve($revenueAdjustment, $request->user());
        return back()->with('status', 'Adjustment approved and audited.');
    }

    public function rejectAdjustment(Request $request, RevenueAdjustment $revenueAdjustment, RevenueAdjustmentService $adjustments): RedirectResponse
    {
        $adjustments->reject($revenueAdjustment, $request->user());
        return back()->with('status', 'Adjustment rejected.');
    }

    public function closePeriod(Request $request, FinancialPeriod $financialPeriod, FinancialPeriodService $periods): RedirectResponse
    {
        $request->validate(['confirm_close' => ['required', 'accepted']]);
        $periods->close($financialPeriod, $request->user());
        return back()->with('status', "Financial period {$financialPeriod->period_key} closed.");
    }

    public function statement(PublisherStatement $publisherStatement): View
    {
        return view('admin.reporting.statement', ['statement' => $publisherStatement->load(['publisher', 'period', 'payments'])]);
    }

    public function statementCsv(PublisherStatement $publisherStatement, PublisherStatementService $statements)
    {
        return $statements->csv($publisherStatement);
    }

    public function storePayment(Request $request, PublisherStatement $publisherStatement, PublisherPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:48'],
            'scheduled_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $payments->create($publisherStatement, (int) $data['amount_minor'], $data, $request->user());
        return back()->with('status', 'Publisher payment created.');
    }

    public function approvePayment(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $payments->approve($publisherPayment, $request->user());
        return back()->with('status', 'Publisher payment approved.');
    }

    public function markPaymentPaid(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'horus_payment_reference' => ['required', 'string', 'max:255'],
            'settled_amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);
        $payments->markPaid(
            $publisherPayment,
            $data['horus_payment_reference'],
            $request->user(),
            isset($data['settled_amount_minor']) ? (int) $data['settled_amount_minor'] : null,
        );
        return back()->with('status', 'Payment settlement recorded.');
    }

    private function ruleData(Request $request, bool $includeScope = true): array
    {
        $rules = [
            'name' => [$includeScope ? 'required' : 'sometimes', 'string', 'max:255'],
            'scope_type' => [$includeScope ? 'required' : 'sometimes', Rule::enum(RevenueRuleScope::class)],
            'scope_id' => ['nullable', 'string', 'max:64'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'publisher_share_bp' => ['required', 'integer', 'between:0,10000'],
            'horus_share_bp' => ['required', 'integer', 'between:0,10000'],
            'mcm_partner_share_bp' => ['nullable', 'integer', 'between:0,10000'],
            'priority' => ['nullable', 'integer', 'between:0,100000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => ['nullable', 'string', 'max:10000'],
        ];
        return $request->validate($rules);
    }
}
