<?php

namespace App\Services\Reporting;

use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherStatementStatus;
use App\Models\FinancialPeriod;
use App\Models\MonthlyReport;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Models\PublisherStatement;
use App\Models\RevenueAdjustment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Uploads\SecureUploadService;
use App\Support\Csv;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublisherStatementService
{
    public function __construct(private readonly AuditRecorder $audit, private readonly SecureUploadService $uploads) {}

    public function generate(FinancialPeriod $period, Publisher $publisher, ?User $actor): PublisherStatement
    {
        $rows = MonthlyReport::withoutGlobalScopes()
            ->with(['dimension.site', 'connection.source'])
            ->where('financial_period_id', $period->id)
            ->whereHas('dimension', fn ($query) => $query->where('publisher_id', $publisher->id))
            ->get();

        $previous = PublisherStatement::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('currency', $period->currency)
            ->whereHas('period', fn ($query) => $query->where('ends_on', '<', $period->starts_on))
            ->latest('created_at')
            ->first();
        $opening = max(0, (int) ($previous?->carry_forward_minor ?? 0));

        $gross = (int) $rows->sum('gross_revenue_minor');
        $baseDeductions = (int) $rows->sum(fn ($row) => (int) $row->demand_partner_deductions_minor
            + (int) $row->invalid_traffic_adjustments_minor
            + (int) $row->other_adjustments_minor
        );
        $siteIds = $publisher->sites()->pluck('id');
        $adjustments = RevenueAdjustment::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('currency', $period->currency)
            ->where('status', 'APPROVED')
            ->where(function ($query) use ($publisher, $siteIds): void {
                $query->where('publisher_id', $publisher->id)
                    ->orWhereIn('site_id', $siteIds);
            })
            ->get();
        $adjustmentDeductions = (int) $adjustments->sum('amount_minor');
        $publisherAdjustmentImpact = (int) $adjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'publisher_impact_minor', 0)
        );
        $deductions = $baseDeductions + $adjustmentDeductions;
        $net = max(0, (int) $rows->sum('net_revenue_minor') - $adjustmentDeductions);
        $earnings = max(0, (int) $rows->sum('publisher_earnings_minor') - $publisherAdjustmentImpact);
        $balance = $opening + $earnings;

        $contract = PublisherContract::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('status', 'ACTIVE')
            ->whereDate('starts_at', '<=', $period->ends_on)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $period->starts_on))
            ->latest('starts_at')
            ->first();
        try {
            $threshold = Money::decimalToMinor((string) ($contract?->payment_threshold ?? '0'));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'payment_threshold' => 'The active contract payment threshold is not a valid two-decimal money value.',
            ]);
        }

        $lineItems = $rows->groupBy(fn ($row) => ($row->connection?->source?->code?->value ?? $row->connection?->source?->code ?? 'UNKNOWN')
            .'|'.($row->dimension?->site_id ?? 'all')
        )->map(function ($group, $key): array {
            [$source, $siteId] = array_pad(explode('|', (string) $key, 2), 2, null);
            $first = $group->first();

            return [
                'source' => $source,
                'site_id' => $siteId !== 'all' ? $siteId : null,
                'site' => $first->dimension?->site?->display_name,
                'impressions' => (int) $group->sum('impressions'),
                'gross_revenue_minor' => (int) $group->sum('gross_revenue_minor'),
                'net_revenue_minor' => (int) $group->sum('net_revenue_minor'),
                'publisher_earnings_minor' => (int) $group->sum('publisher_earnings_minor'),
            ];
        })->values()->all();
        foreach ($adjustments as $adjustment) {
            $lineItems[] = [
                'source' => 'ADJUSTMENT',
                'site_id' => $adjustment->site_id,
                'site' => null,
                'description' => $adjustment->reason,
                'type' => $adjustment->type,
                'impressions' => 0,
                'gross_revenue_minor' => 0,
                'net_revenue_minor' => -((int) $adjustment->amount_minor),
                'publisher_earnings_minor' => -((int) data_get($adjustment->metadata, 'publisher_impact_minor', 0)),
            ];
        }

        $snapshot = [
            'period_key' => $period->period_key,
            'publisher_id' => $publisher->id,
            'currency' => $period->currency,
            'opening_balance_minor' => $opening,
            'gross_revenue_minor' => $gross,
            'deductions_minor' => $deductions,
            'net_revenue_minor' => $net,
            'publisher_earnings_minor' => $earnings,
            'payment_threshold_minor' => $threshold,
            'approved_adjustment_ids' => $adjustments->pluck('id')->sort()->values()->all(),
            'line_items' => $lineItems,
            'monthly_report_hashes' => $rows->pluck('snapshot_hash')->sort()->values()->all(),
        ];
        $hash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        $status = $balance < $threshold
            ? PublisherStatementStatus::BelowThreshold
            : PublisherStatementStatus::PendingInvoice;
        $carryForward = $status === PublisherStatementStatus::BelowThreshold ? $balance : 0;

        $statement = PublisherStatement::withoutGlobalScopes()->updateOrCreate(
            [
                'publisher_id' => $publisher->id,
                'financial_period_id' => $period->id,
                'currency' => $period->currency,
            ],
            [
                'organization_id' => $publisher->organization_id,
                'statement_number' => 'HM-PS-'.$period->period_key.'-'.Str::upper(substr(hash('sha256', $publisher->id), 0, 10)),
                'status' => $status,
                'opening_balance_minor' => $opening,
                'gross_revenue_minor' => $gross,
                'deductions_minor' => $deductions,
                'net_revenue_minor' => $net,
                'publisher_earnings_minor' => $earnings,
                'paid_minor' => 0,
                'balance_due_minor' => $balance,
                'carry_forward_minor' => $carryForward,
                'payment_threshold_minor' => $threshold,
                'revenue_rule_version_id' => $rows->pluck('revenue_rule_version_id')->filter()->unique()->count() === 1
                    ? $rows->pluck('revenue_rule_version_id')->filter()->first()
                    : null,
                'line_items' => $lineItems,
                'snapshot' => $snapshot,
                'snapshot_hash' => $hash,
                'finalized_at' => now(),
                'finalized_by' => $actor?->id,
                'publisher_invoice_status' => $status === PublisherStatementStatus::PendingInvoice
                    ? PublisherInvoiceStatus::Required
                    : PublisherInvoiceStatus::NotRequired,
            ],
        );

        $this->audit->record('reporting.publisher_statement.generated', $publisher->organization_id, $actor, $statement, newValues: [
            'statement_number' => $statement->statement_number,
            'period_key' => $period->period_key,
            'balance_due_minor' => $balance,
            'status' => $status->value,
            'snapshot_hash' => $hash,
        ]);

        return $statement->refresh();
    }

    public function uploadInvoice(PublisherStatement $statement, UploadedFile $file, string $invoiceNumber, User $actor): PublisherStatement
    {
        if (! $actor->isHorusAdministrator() && (
            $statement->publisher_id !== $actor->organization?->publisher?->id
            || $statement->organization_id !== $actor->organization_id
        )) {
            abort(403);
        }
        $statement = PublisherStatement::withoutGlobalScopes()->findOrFail($statement->id);
        if ((int) $statement->balance_due_minor < (int) $statement->payment_threshold_minor) {
            throw ValidationException::withMessages(['invoice' => 'An invoice is not required while this statement remains below threshold.']);
        }
        if (in_array($statement->publisher_invoice_status, [PublisherInvoiceStatus::Received, PublisherInvoiceStatus::Accepted], true)) {
            throw ValidationException::withMessages(['invoice' => 'An invoice has already been received for this statement.']);
        }
        $stored = $this->uploads->store($file, 'publisher-invoices/'.$statement->publisher_id.'/'.$statement->financial_period_id, [
            'application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg',
        ], (int) config('security.uploads.invoice_max_bytes'));
        $checksum = $stored['checksum'];
        $path = $stored['path'];
        $statement->update([
            'publisher_invoice_number' => $invoiceNumber,
            'publisher_invoice_path' => $path,
            'publisher_invoice_uploaded_at' => now(),
            'publisher_invoice_uploaded_by' => $actor->id,
            'publisher_invoice_status' => PublisherInvoiceStatus::Received,
            'publisher_invoice_reviewed_at' => null,
            'publisher_invoice_reviewed_by' => null,
            'publisher_invoice_review_reason' => null,
            'status' => PublisherStatementStatus::PendingInvoice,
        ]);

        $this->audit->record('reporting.publisher_invoice.uploaded', $statement->organization_id, $actor, $statement, newValues: [
            'publisher_invoice_number' => $invoiceNumber,
            'checksum' => $checksum,
            'path' => '[PRIVATE_FILE]',
        ]);

        return $statement->refresh();
    }

    public function reviewInvoice(
        PublisherStatement $statement,
        PublisherInvoiceStatus $status,
        User $actor,
        ?string $reason = null,
    ): PublisherStatement {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission('finance.statements.review')) {
            abort(403);
        }
        if (! in_array($status, [PublisherInvoiceStatus::Accepted, PublisherInvoiceStatus::Rejected], true)) {
            throw ValidationException::withMessages(['publisher_invoice_status' => 'Only acceptance or rejection can be recorded.']);
        }
        if ($status === PublisherInvoiceStatus::Rejected && blank($reason)) {
            throw ValidationException::withMessages(['review_reason' => 'A safe Publisher-visible rejection reason is required.']);
        }

        return DB::transaction(function () use ($statement, $status, $actor, $reason): PublisherStatement {
            $statement = PublisherStatement::withoutGlobalScopes()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->publisher_invoice_status === $status) {
                return $statement;
            }
            if ($statement->publisher_invoice_status !== PublisherInvoiceStatus::Received) {
                throw ValidationException::withMessages(['publisher_invoice_status' => 'Only a received invoice can be reviewed.']);
            }
            if (! $statement->publisher_invoice_path || ! $statement->publisher_invoice_number) {
                throw ValidationException::withMessages(['publisher_invoice_status' => 'The invoice file and number must exist before review.']);
            }

            $before = $statement->publisher_invoice_status;
            $statement->update([
                'publisher_invoice_status' => $status,
                'publisher_invoice_reviewed_at' => now(),
                'publisher_invoice_reviewed_by' => $actor->id,
                'publisher_invoice_review_reason' => filled($reason) ? trim((string) $reason) : null,
                'status' => $status === PublisherInvoiceStatus::Accepted
                    ? PublisherStatementStatus::Payable
                    : PublisherStatementStatus::PendingInvoice,
            ]);
            $this->audit->record('finance.publisher_invoice.reviewed', $statement->organization_id, $actor, $statement, [
                'publisher_invoice_status' => $before->value,
            ], [
                'publisher_invoice_status' => $status->value,
                'review_reason' => filled($reason) ? trim((string) $reason) : null,
                'statement_status' => $statement->status->value,
            ]);

            return $statement->refresh();
        });
    }

    public function csv(PublisherStatement $statement, bool $publisherSafe = false): StreamedResponse
    {
        return response()->streamDownload(function () use ($statement, $publisherSafe): void {
            $handle = fopen('php://output', 'wb');
            if ($publisherSafe) {
                fputcsv($handle, ['Category', 'Description', 'Amount Minor', 'Currency', 'Impressions']);
                foreach ([
                    ['Opening balance', $statement->opening_balance_minor],
                    ['Publisher earnings', $statement->publisher_earnings_minor],
                    ['Deductions', -((int) $statement->deductions_minor)],
                    ['Paid', $statement->paid_minor],
                    ['Balance due', $statement->balance_due_minor],
                    ['Carry-forward', $statement->carry_forward_minor],
                    ['Payment threshold', $statement->payment_threshold_minor],
                ] as [$description, $amount]) {
                    fputcsv($handle, ['SUMMARY', $description, $amount, $statement->currency, '']);
                }
                foreach ($statement->line_items ?? [] as $row) {
                    $description = ($row['source'] ?? '') === 'ADJUSTMENT'
                        ? 'Approved adjustment'
                        : ($row['site'] ?? 'All Publisher inventory');
                    fputcsv($handle, [
                        'PUBLISHER_EARNINGS',
                        Csv::safeCell($description),
                        $row['publisher_earnings_minor'] ?? 0,
                        $statement->currency,
                        $row['impressions'] ?? 0,
                    ]);
                }
                fclose($handle);

                return;
            }

            fputcsv($handle, ['Source', 'Website', 'Impressions', 'Gross Revenue Minor', 'Net Revenue Minor', 'Publisher Earnings Minor']);
            foreach ($statement->line_items ?? [] as $row) {
                fputcsv($handle, [
                    Csv::safeCell($row['source'] ?? ''),
                    Csv::safeCell($row['site'] ?? ''),
                    $row['impressions'] ?? 0,
                    $row['gross_revenue_minor'] ?? 0,
                    $row['net_revenue_minor'] ?? 0,
                    $row['publisher_earnings_minor'] ?? 0,
                ]);
            }
            fclose($handle);
        }, $statement->statement_number.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
