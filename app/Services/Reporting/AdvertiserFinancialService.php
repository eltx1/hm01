<?php

namespace App\Services\Reporting;

use App\Models\AdvertiserInvoice;
use App\Models\Campaign;
use App\Services\Audit\AuditRecorder;
use App\Models\User;

final class AdvertiserFinancialService
{
    public function __construct(
        private readonly UnifiedReportService $reports,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function synchronizeInvoice(AdvertiserInvoice $invoice, ?User $actor = null): AdvertiserInvoice
    {
        if (! $invoice->campaign_id) {
            return $invoice;
        }
        $campaign = Campaign::withoutGlobalScopes()->findOrFail($invoice->campaign_id);
        $report = $this->reports->campaignCost($campaign);
        $subtotal = (int) $report['spend_minor'];
        $taxRate = (int) config('campaigns.invoice_tax_basis_points', 0);
        $tax = intdiv($subtotal * $taxRate, 10000);
        $total = $subtotal + $tax;
        $paid = (int) $invoice->amount_paid_minor;
        $snapshot = [
            'campaign' => $campaign->only(['id', 'name', 'currency', 'pricing_model']),
            'report' => $report,
            'generated_at' => now()->toIso8601String(),
        ];

        $invoice->update([
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'balance_due_minor' => max(0, $total - $paid),
            'line_items' => [[
                'description' => $campaign->name.' campaign delivery',
                'impressions' => $report['impressions'],
                'clicks' => $report['clicks'],
                'amount_minor' => $subtotal,
            ]],
            'report_snapshot' => $snapshot,
            'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
        ]);

        $this->audit->record('reporting.advertiser_invoice.synchronized', $invoice->organization_id, $actor, $invoice, newValues: [
            'invoice_number' => $invoice->invoice_number,
            'total_minor' => $total,
            'balance_due_minor' => $invoice->balance_due_minor,
            'snapshot_hash' => $invoice->snapshot_hash,
        ]);

        return $invoice->refresh();
    }
}
