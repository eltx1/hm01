<?php

namespace App\Services\Reporting;

use App\Enums\PublisherStatementStatus;
use App\Models\FinancialPeriod;
use App\Models\MonthlyReport;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Models\PublisherStatement;
use App\Models\RevenueAdjustment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublisherStatementService
{
    public function __construct(private readonly AuditRecorder $audit)
    {
    }

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
        $baseDeductions = (int) $rows->sum(fn ($row) =>
            (int) $row->demand_partner_deductions_minor
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
        $publisherAdjustmentImpact = (int) $adjustments->sum(fn ($adjustment) =>
            (int) data_get($adjustment->metadata, 'publisher_impact_minor', 0)
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
        $threshold = (int) round(((float) ($contract?->payment_threshold ?? 0)) * 100);

        $lineItems = $rows->groupBy(fn ($row) =>
            ($row->connection?->source?->code?->value ?? $row->connection?->source?->code ?? 'UNKNOWN')
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
        if ($statement->publisher_id !== $actor->organization?->publisher?->id && ! $actor->isHorusAdministrator()) {
            abort(403);
        }
        if (! $file->isValid() || $file->getSize() > (int) config('reporting.statement_invoice_max_bytes', 10 * 1024 * 1024)) {
            throw ValidationException::withMessages(['invoice' => 'The publisher invoice is invalid or exceeds the configured limit.']);
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['pdf', 'png', 'jpg', 'jpeg'], true)) {
            throw ValidationException::withMessages(['invoice' => 'Publisher invoices must be PDF, PNG, or JPEG files.']);
        }

        $checksum = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs(
            'publisher-invoices/'.$statement->publisher_id.'/'.$statement->financial_period_id,
            $checksum.'.'.$extension,
            'local',
        );
        $statement->update([
            'publisher_invoice_number' => $invoiceNumber,
            'publisher_invoice_path' => $path,
            'publisher_invoice_uploaded_at' => now(),
            'publisher_invoice_uploaded_by' => $actor->id,
            'status' => $statement->balance_due_minor >= $statement->payment_threshold_minor
                ? PublisherStatementStatus::Payable
                : $statement->status,
        ]);

        $this->audit->record('reporting.publisher_invoice.uploaded', $statement->organization_id, $actor, $statement, newValues: [
            'publisher_invoice_number' => $invoiceNumber,
            'checksum' => $checksum,
            'path' => '[PRIVATE_FILE]',
        ]);

        return $statement->refresh();
    }

    public function csv(PublisherStatement $statement): StreamedResponse
    {
        return response()->streamDownload(function () use ($statement): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Source', 'Website', 'Impressions', 'Gross Revenue Minor', 'Net Revenue Minor', 'Publisher Earnings Minor']);
            foreach ($statement->line_items ?? [] as $row) {
                fputcsv($handle, [
                    $row['source'] ?? '',
                    $row['site'] ?? '',
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
