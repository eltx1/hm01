<?php

namespace App\Services\ControlPlane\Actions;

use App\Enums\PublisherPaymentStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ReportImportStatus;
use App\Models\Publisher;
use App\Models\ReportImportJob;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;
use Illuminate\Support\Facades\DB;

final class FinanceActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        $items = [];
        $financeCounts = null;
        if ($user->hasPermission('finance.operations.view') || $user->hasPermission('finance.payments.view') || $user->hasPermission('finance.reconciliation.manage')) {
            $financeCounts = DB::query()->selectRaw(<<<'SQL'
                (SELECT COUNT(*) FROM publisher_statements ps
                    WHERE ps.balance_due_minor > 0 AND ps.financial_period_id = (
                        SELECT latest_statements.financial_period_id FROM publisher_statements latest_statements
                        INNER JOIN financial_periods periods ON periods.id = latest_statements.financial_period_id
                        WHERE latest_statements.publisher_id = ps.publisher_id AND latest_statements.currency = ps.currency
                        ORDER BY periods.ends_on DESC, latest_statements.created_at DESC, latest_statements.id DESC LIMIT 1
                    )) AS outstanding_balances,
                (SELECT COUNT(*) FROM publisher_payments WHERE status IN (?, ?)) AS failed_payouts,
                (SELECT COUNT(*) FROM reconciliation_runs WHERE status IN (?, ?)) AS reconciliation_mismatches
                SQL, [
                PublisherPaymentStatus::Failed->value, PublisherPaymentStatus::Held->value,
                ReconciliationStatus::Warning->value, ReconciliationStatus::Failed->value,
            ])->first();
        }

        if ($user->hasPermission('reporting.import')) {
            $items[] = $this->item('report-imports', 'Failed report imports', ReportImportJob::withoutGlobalScopes()
                ->where('status', ReportImportStatus::Failed->value)->count(),
                'Aggregated report imports are eligible for investigation or retry.', 'admin.reporting.index', 10, 'danger');
        }

        if ($user->hasPermission('finance.payment_profiles.verify')) {
            $items[] = $this->item('payment-profiles', 'Payment profiles not verified', Publisher::withoutGlobalScopes()
                ->where(function ($query): void {
                    $query->whereDoesntHave('paymentProfile')
                        ->orWhereHas('paymentProfile', fn ($profile) => $profile->where('verification_status', '!=', 'VERIFIED'));
                })->count(),
                'Publisher payment details are missing or awaiting verification.', 'admin.finance.payment-profiles.index', 30);
        }

        if ($user->hasPermission('finance.operations.view')) {
            $items[] = $this->item('publisher-balances', 'Outstanding publisher balances', (int) ($financeCounts->outstanding_balances ?? 0),
                'Finalized statements have a remaining publisher balance.', 'admin.finance.overview', 40, 'neutral');
        }

        if ($user->hasPermission('finance.payments.view')) {
            $items[] = $this->item('failed-payouts', 'Failed or held payouts', (int) ($financeCounts->failed_payouts ?? 0),
                'Publisher payouts require explicit Finance remediation.', 'admin.finance.payouts.index', 5, 'danger');
        }

        if ($user->hasPermission('finance.reconciliation.manage')) {
            $items[] = $this->item('reconciliation-mismatch', 'Reconciliation discrepancies', (int) ($financeCounts->reconciliation_mismatches ?? 0),
                'Source and normalized totals require review without silent financial mutation.', 'admin.finance.reconciliation.index', 5, 'danger');
        }

        return $items;
    }

    private function item(string $key, string $label, int $count, string $description, string $route, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'route', 'priority', 'severity') + ['parameters' => []];
    }
}
