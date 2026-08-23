<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdjustmentStatus;
use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\RevenueRuleScope;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\DemandNetwork;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentProfile;
use App\Models\PublisherStatement;
use App\Models\ReconciliationRun;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\RevenueAdjustment;
use App\Models\RevenueRule;
use App\Models\Site;
use App\Services\Reporting\AdminFinanceService;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Reporting\PublisherPaymentService;
use App\Services\Reporting\PublisherStatementService;
use App\Services\Reporting\ReconciliationService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\RevenueAdjustmentService;
use App\Services\Reporting\RevenueRuleService;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FinanceOperationsController extends Controller
{
    public function overview(AdminFinanceService $finance): View
    {
        return view('admin.finance.overview', $finance->overview());
    }

    public function periods(AdminFinanceService $finance): View
    {
        return view('admin.finance.periods', ['periodRows' => $finance->periodRows(36)]);
    }

    public function closePeriod(Request $request, FinancialPeriod $financialPeriod, FinancialPeriodService $periods): RedirectResponse
    {
        $data = $request->validate([
            'confirm_close' => ['required', 'accepted'],
            'override_close' => ['sometimes', 'accepted'],
            'override_reason' => ['nullable', 'required_if:override_close,1', 'string', 'min:12', 'max:2000'],
        ]);
        $periods->close(
            $financialPeriod,
            $request->user(),
            $request->boolean('override_close') ? $data['override_reason'] : null,
        );

        return back()->with('status', "Financial period {$financialPeriod->period_key} closed.");
    }

    public function statements(Request $request, AdminFinanceService $finance): View
    {
        $query = PublisherStatement::withoutGlobalScopes()
            ->with(['publisher.paymentProfile', 'period', 'payments.settlements'])
            ->latest('finalized_at');
        $this->filterEnum($query, $request, 'status', PublisherStatementStatus::class);
        $this->filterEnum($query, $request, 'invoice_status', PublisherInvoiceStatus::class, 'publisher_invoice_status');
        $this->filterCurrency($query, $request);

        return view('admin.finance.statements', [
            'statements' => $query->paginate(50)->withQueryString(),
            'finance' => $finance,
            'statementStatuses' => PublisherStatementStatus::cases(),
            'invoiceStatuses' => PublisherInvoiceStatus::cases(),
        ]);
    }

    public function statement(PublisherStatement $publisherStatement): View
    {
        return view('admin.reporting.statement', [
            'statement' => $publisherStatement->load(['publisher.paymentProfile', 'period', 'payments.settlements']),
        ]);
    }

    public function statementCsv(PublisherStatement $publisherStatement, PublisherStatementService $statements): StreamedResponse
    {
        return $statements->csv($publisherStatement);
    }

    public function reviewInvoice(
        Request $request,
        PublisherStatement $publisherStatement,
        PublisherStatementService $statements,
    ): RedirectResponse {
        $data = $request->validate([
            'publisher_invoice_status' => ['required', Rule::in([
                PublisherInvoiceStatus::Accepted->value,
                PublisherInvoiceStatus::Rejected->value,
            ])],
            'review_reason' => ['nullable', 'required_if:publisher_invoice_status,REJECTED', 'string', 'max:1000'],
        ]);
        $statements->reviewInvoice(
            $publisherStatement,
            PublisherInvoiceStatus::from($data['publisher_invoice_status']),
            $request->user(),
            $data['review_reason'] ?? null,
        );

        return back()->with('status', 'Publisher invoice review recorded.');
    }

    public function createPayout(
        Request $request,
        PublisherStatement $publisherStatement,
        PublisherPaymentService $payments,
    ): RedirectResponse {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:48'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);
        $payments->create($publisherStatement, (int) $data['amount_minor'], $data, $request->user());

        return back()->with('status', 'Payout created for independent approval.');
    }

    public function createSelectedPayouts(
        Request $request,
        PublisherPaymentService $payments,
        AdminFinanceService $finance,
    ): RedirectResponse {
        $data = $request->validate([
            'statement_ids' => ['required', 'array', 'min:1', 'max:100'],
            'statement_ids.*' => ['required', 'distinct', 'ulid', 'exists:publisher_statements,id'],
            'batch_key' => ['required', 'string', 'max:64'],
        ]);
        $ids = collect($data['statement_ids'])->sort()->values();
        $created = DB::transaction(function () use ($ids, $data, $payments, $finance, $request): int {
            $statements = PublisherStatement::withoutGlobalScopes()
                ->with(['publisher.paymentProfile', 'payments'])
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->get()
                ->keyBy('id');
            if ($statements->count() !== $ids->count()) {
                abort(404);
            }
            foreach ($ids as $id) {
                $statement = $statements->get($id);
                if (! $finance->isEligible($statement)) {
                    throw ValidationException::withMessages([
                        'statement_ids' => "Statement {$statement->statement_number} is not payout eligible.",
                    ]);
                }
                $payments->create($statement, $finance->unreservedBalance($statement), [
                    'idempotency_key' => hash('sha256', $data['batch_key'].'|'.$statement->id),
                ], $request->user());
            }

            return $ids->count();
        });

        return back()->with('status', "{$created} payout(s) created for independent approval.");
    }

    public function payouts(Request $request): View
    {
        return view('admin.finance.payouts', [
            'payments' => $this->paymentQuery($request)->paginate(50)->withQueryString(),
            'paymentStatuses' => PublisherPaymentStatus::cases(),
        ]);
    }

    public function payoutsCsv(Request $request): StreamedResponse
    {
        $payments = $this->paymentQuery($request)->get();

        return response()->streamDownload(function () use ($payments): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Payment', 'Publisher', 'Statement', 'Period', 'Status', 'Requested Minor', 'Settled Minor', 'Remaining Minor', 'Currency', 'Method', 'Scheduled', 'Paid', 'Reference']);
            foreach ($payments as $payment) {
                fputcsv($handle, [
                    Csv::safeCell($payment->payment_number),
                    Csv::safeCell($payment->publisher?->display_name),
                    Csv::safeCell($payment->statement?->statement_number),
                    Csv::safeCell($payment->statement?->period?->period_key),
                    $payment->status->value,
                    (int) $payment->amount_minor,
                    (int) $payment->settled_amount_minor,
                    $payment->remainingAmountMinor(),
                    $payment->currency,
                    Csv::safeCell($payment->payment_method),
                    $payment->scheduled_on?->toDateString(),
                    $payment->paid_at?->toIso8601String(),
                    Csv::safeCell($payment->horus_payment_reference),
                ]);
            }
            fclose($handle);
        }, 'publisher-payouts-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function approvePayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $request->validate(['confirm_approval' => ['required', 'accepted']]);
        $payments->approve($publisherPayment, $request->user());

        return back()->with('status', 'Payout approved. No external money movement has been claimed.');
    }

    public function schedulePayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['scheduled_on' => ['required', 'date', 'after_or_equal:today']]);
        $payments->schedule($publisherPayment, $data['scheduled_on'], $request->user());

        return back()->with('status', 'Payout scheduled. Settlement is still required after external processing.');
    }

    public function processPayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $request->validate(['confirm_external_processing' => ['required', 'accepted']]);
        $payments->beginExternalProcessing($publisherPayment, $request->user());

        return back()->with('status', 'External processing recorded; this does not mark the payout paid.');
    }

    public function settlePayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $data = $request->validate([
            'settlement_reference' => ['required', 'string', 'max:255'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'settled_on' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $payments->recordSettlement(
            $publisherPayment,
            $data['settlement_reference'],
            (int) $data['amount_minor'],
            $data['settled_on'],
            $request->user(),
        );

        return back()->with('status', 'Immutable settlement recorded and statement balance reconciled.');
    }

    public function holdPayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $payments->hold($publisherPayment, $data['reason'], $request->user());

        return back()->with('status', 'Payout placed on hold; earned balance was not changed.');
    }

    public function releasePayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $request->validate(['confirm_release' => ['required', 'accepted']]);
        $payments->releaseHold($publisherPayment, $request->user());

        return back()->with('status', 'Payout hold released to approved state.');
    }

    public function failPayout(Request $request, PublisherPayment $publisherPayment, PublisherPaymentService $payments): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $payments->fail($publisherPayment, $data['reason'], $request->user());

        return back()->with('status', 'Payout marked failed; only recorded settlements affect the statement balance.');
    }

    public function paymentProfiles(Request $request): View
    {
        $query = Publisher::withoutGlobalScopes()->with(['paymentProfile.verifier'])->orderBy('display_name');
        $status = strtoupper($request->string('status')->value());
        if ($status !== '' && in_array($status, array_column(PublisherPaymentProfileStatus::cases(), 'value'), true)) {
            $query->where(function (Builder $builder) use ($status): void {
                if ($status === PublisherPaymentProfileStatus::Incomplete->value) {
                    $builder->whereDoesntHave('paymentProfile')
                        ->orWhereHas('paymentProfile', fn ($profiles) => $profiles->where('verification_status', $status));
                } else {
                    $builder->whereHas('paymentProfile', fn ($profiles) => $profiles->where('verification_status', $status));
                }
            });
        }

        return view('admin.finance.payment-profiles', [
            'publishers' => $query->paginate(50)->withQueryString(),
            'profileStatuses' => PublisherPaymentProfileStatus::cases(),
        ]);
    }

    public function reviewPaymentProfile(
        Request $request,
        PublisherPaymentProfile $publisherPaymentProfile,
        PublisherPaymentProfileService $profiles,
    ): RedirectResponse {
        $data = $request->validate([
            'verification_status' => ['required', Rule::in(['VERIFIED', 'REJECTED', 'PENDING_VERIFICATION'])],
            'verification_reason' => ['nullable', 'required_if:verification_status,REJECTED', 'string', 'max:1000'],
        ]);
        $profiles->review(
            $publisherPaymentProfile,
            PublisherPaymentProfileStatus::from($data['verification_status']),
            $request->user(),
            $data['verification_reason'] ?? null,
        );

        return back()->with('status', 'Payment-profile verification decision recorded.');
    }

    public function revenueRules(): View
    {
        $rules = RevenueRule::withoutGlobalScopes()
            ->with(['versions.creator', 'currentVersion', 'creator'])
            ->orderByDesc('priority')->get();

        return view('admin.finance.revenue-rules', [
            'rules' => $rules,
            'scopeLabels' => $this->scopeLabels($rules),
            'ruleScopes' => RevenueRuleScope::cases(),
            'publishers' => Publisher::withoutGlobalScopes()->orderBy('display_name')->get(),
            'sites' => Site::withoutGlobalScopes()->with('publisher')->orderBy('display_name')->get(),
            'campaigns' => Campaign::withoutGlobalScopes()->orderBy('name')->get(),
            'reportSources' => ReportSource::query()->orderBy('name')->get(),
            'demandNetworks' => DemandNetwork::withoutGlobalScopes()->orderBy('name')->get(),
        ]);
    }

    public function storeRevenueRule(Request $request, RevenueRuleService $rules): RedirectResponse
    {
        $rules->createRule($this->ruleData($request), $request->user());

        return back()->with('status', 'Revenue rule created with immutable version 1.');
    }

    public function versionRevenueRule(Request $request, RevenueRule $revenueRule, RevenueRuleService $rules): RedirectResponse
    {
        $rules->changeRule($revenueRule, $this->ruleData($request, false), $request->user());

        return back()->with('status', 'New effective revenue-rule version created; history was not overwritten.');
    }

    public function adjustments(): View
    {
        return view('admin.finance.adjustments', [
            'adjustments' => RevenueAdjustment::withoutGlobalScopes()
                ->with(['period', 'publisher', 'site', 'campaign', 'creator', 'approver'])->latest()->paginate(50),
            'periods' => FinancialPeriod::query()->where('status', 'OPEN')->latest('starts_on')->get(),
            'publishers' => Publisher::withoutGlobalScopes()->orderBy('display_name')->get(),
            'sites' => Site::withoutGlobalScopes()->with('publisher')->orderBy('display_name')->get(),
            'adjustmentStatuses' => AdjustmentStatus::cases(),
        ]);
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

        return back()->with('status', 'Adjustment created and awaits a different approver.');
    }

    public function approveAdjustment(Request $request, RevenueAdjustment $revenueAdjustment, RevenueAdjustmentService $adjustments): RedirectResponse
    {
        $data = $request->validate(['decision_reason' => ['nullable', 'string', 'max:2000']]);
        $adjustments->approve($revenueAdjustment, $request->user(), $data['decision_reason'] ?? null);

        return back()->with('status', 'Adjustment approved and financial impact fixed.');
    }

    public function rejectAdjustment(Request $request, RevenueAdjustment $revenueAdjustment, RevenueAdjustmentService $adjustments): RedirectResponse
    {
        $data = $request->validate(['decision_reason' => ['required', 'string', 'max:2000']]);
        $adjustments->reject($revenueAdjustment, $request->user(), $data['decision_reason']);

        return back()->with('status', 'Adjustment rejected.');
    }

    public function reconciliation(Request $request): View
    {
        $query = ReconciliationRun::withoutGlobalScopes()
            ->with(['connection.source', 'import', 'remediator'])
            ->latest('started_at');
        $this->filterEnum($query, $request, 'status', ReconciliationStatus::class);

        return view('admin.finance.reconciliation', [
            'runs' => $query->paginate(50)->withQueryString(),
            'failedImports' => ReportImportJob::withoutGlobalScopes()
                ->with('connection.source')->where('status', 'FAILED')->latest()->limit(50)->get(),
            'reconciliationStatuses' => ReconciliationStatus::cases(),
        ]);
    }

    public function remediateReconciliation(Request $request, ReconciliationRun $reconciliationRun, ReconciliationService $reconciliation): RedirectResponse
    {
        $data = $request->validate(['remediation_note' => ['required', 'string', 'min:12', 'max:2000']]);
        $reconciliation->recordRemediation($reconciliationRun, $data['remediation_note'], $request->user());

        return back()->with('status', 'Remediation note recorded without mutating finalized finance.');
    }

    public function retryImport(Request $request, ReportImportJob $reportImportJob, ReportImportService $imports): RedirectResponse
    {
        $job = $imports->retry($reportImportJob, $request->user());

        return back()->with($job->status->value === 'COMPLETED' ? 'status' : 'error', "Retry {$job->status->value}.");
    }

    private function paymentQuery(Request $request): Builder
    {
        $query = PublisherPayment::withoutGlobalScopes()
            ->with(['publisher', 'statement.period', 'settlements', 'creator', 'approver'])
            ->latest();
        $this->filterEnum($query, $request, 'status', PublisherPaymentStatus::class);
        $this->filterCurrency($query, $request);

        return $query;
    }

    private function filterCurrency(Builder $query, Request $request): void
    {
        $currency = strtoupper($request->string('currency')->value());
        if (preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $query->where('currency', $currency);
        }
    }

    private function filterEnum(Builder $query, Request $request, string $parameter, string $enumClass, ?string $column = null): void
    {
        $value = strtoupper($request->string($parameter)->value());
        if ($value !== '' && in_array($value, array_column($enumClass::cases(), 'value'), true)) {
            $query->where($column ?? $parameter, $value);
        }
    }

    private function ruleData(Request $request, bool $includeScope = true): array
    {
        $data = $request->validate([
            'name' => [$includeScope ? 'required' : 'sometimes', 'string', 'max:255'],
            'scope_type' => [$includeScope ? 'required' : 'sometimes', Rule::enum(RevenueRuleScope::class)],
            'scope_id' => ['nullable', 'string', 'max:64'],
            'publisher_scope_id' => ['nullable', 'string', 'max:26'],
            'website_scope_id' => ['nullable', 'string', 'max:26'],
            'demand_scope_id' => ['nullable', 'string', 'max:26'],
            'campaign_scope_id' => ['nullable', 'string', 'max:26'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'publisher_share_percent' => ['nullable', 'numeric', 'between:0,100', 'required_without:publisher_share_bp'],
            'horus_share_percent' => ['nullable', 'numeric', 'between:0,100', 'required_without:horus_share_bp'],
            'mcm_partner_share_percent' => ['nullable', 'numeric', 'between:0,100'],
            'publisher_share_bp' => ['nullable', 'integer', 'between:0,10000'],
            'horus_share_bp' => ['nullable', 'integer', 'between:0,10000'],
            'mcm_partner_share_bp' => ['nullable', 'integer', 'between:0,10000'],
            'priority' => ['nullable', 'integer', 'between:0,100000'],
            'currency' => ['nullable', 'string', 'size:3'],
            'reason' => [$includeScope ? 'nullable' : 'required', 'string', 'max:10000'],
        ]);

        if (array_key_exists('publisher_share_percent', $data)) {
            $data['publisher_share_bp'] = (int) round((float) $data['publisher_share_percent'] * 100);
            $data['horus_share_bp'] = (int) round((float) $data['horus_share_percent'] * 100);
            $data['mcm_partner_share_bp'] = (int) round((float) ($data['mcm_partner_share_percent'] ?? 0) * 100);
        }

        if ($includeScope) {
            $scope = RevenueRuleScope::from($data['scope_type']);
            $data['scope_id'] = match ($scope) {
                RevenueRuleScope::Global => null,
                RevenueRuleScope::Publisher => $data['publisher_scope_id'] ?? $data['scope_id'] ?? null,
                RevenueRuleScope::Website => $data['website_scope_id'] ?? $data['scope_id'] ?? null,
                RevenueRuleScope::DemandSource => $data['demand_scope_id'] ?? $data['scope_id'] ?? null,
                RevenueRuleScope::Campaign => $data['campaign_scope_id'] ?? $data['scope_id'] ?? null,
                RevenueRuleScope::PublisherDemandSource => RevenueRuleService::publisherDemandScopeId(
                    (string) ($data['publisher_scope_id'] ?? ''),
                    (string) ($data['demand_scope_id'] ?? ''),
                ),
            };
        }

        return collect($data)->except([
            'publisher_scope_id', 'website_scope_id', 'demand_scope_id', 'campaign_scope_id',
            'publisher_share_percent', 'horus_share_percent', 'mcm_partner_share_percent',
        ])->all();
    }

    /** @return array<string, string> */
    private function scopeLabels($rules): array
    {
        $publishers = Publisher::withoutGlobalScopes()->pluck('display_name', 'id');
        $sites = Site::withoutGlobalScopes()->pluck('display_name', 'id');
        $campaigns = Campaign::withoutGlobalScopes()->pluck('name', 'id');
        $sources = ReportSource::query()->pluck('name', 'id');
        $demandNetworks = DemandNetwork::withoutGlobalScopes()->pluck('name', 'id');

        return $rules->mapWithKeys(function (RevenueRule $rule) use ($publishers, $sites, $campaigns, $sources, $demandNetworks): array {
            $label = match ($rule->scope_type) {
                RevenueRuleScope::Global => 'All reporting',
                RevenueRuleScope::Publisher => $publishers[$rule->scope_id] ?? $rule->scope_id,
                RevenueRuleScope::Website => $sites[$rule->scope_id] ?? $rule->scope_id,
                RevenueRuleScope::Campaign => $campaigns[$rule->scope_id] ?? $rule->scope_id,
                RevenueRuleScope::DemandSource => $sources[$rule->scope_id] ?? $demandNetworks[$rule->scope_id] ?? $rule->scope_id,
                RevenueRuleScope::PublisherDemandSource => $this->publisherDemandScopeLabel(
                    $rule->scope_id,
                    $publishers,
                    $sources,
                    $demandNetworks,
                ),
            };

            return [$rule->id => (string) $label];
        })->all();
    }

    private function publisherDemandScopeLabel($scopeId, $publishers, $sources, $demandNetworks): string
    {
        [$publisherId, $demandSourceId] = RevenueRuleService::splitPublisherDemandScopeId($scopeId);

        return (string) ($publishers[$publisherId] ?? $publisherId).' · '.(string) ($sources[$demandSourceId]
            ?? $demandNetworks[$demandSourceId]
            ?? $demandSourceId);
    }
}
