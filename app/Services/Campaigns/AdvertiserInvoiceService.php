<?php

namespace App\Services\Campaigns;

use App\Enums\AdvertiserInvoiceStatus;
use App\Models\AdvertiserBillingProfile;
use App\Models\AdvertiserInvoice;
use App\Models\Campaign;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

final class AdvertiserInvoiceService
{
    public function ensureForCampaign(Campaign $campaign): AdvertiserInvoice
    {
        $campaign->loadMissing(['advertiser', 'budget']);
        $existing = AdvertiserInvoice::withoutGlobalScopes()->where('campaign_id', $campaign->id)->first();
        if ($existing) return $existing;

        $profile = AdvertiserBillingProfile::withoutGlobalScopes()
            ->where('advertiser_id', $campaign->advertiser_id)
            ->orderByDesc('is_default')
            ->first();
        $subtotal = (int) $campaign->total_budget_minor;
        $tax = intdiv($subtotal * (int) config('campaigns.invoice_tax_basis_points', 0), 10_000);
        $issued = now()->toDateString();
        $terms = (int) ($profile?->payment_terms_days ?? 0);

        return AdvertiserInvoice::withoutGlobalScopes()->create([
            'organization_id' => $campaign->organization_id,
            'advertiser_id' => $campaign->advertiser_id,
            'advertiser_billing_profile_id' => $profile?->id,
            'campaign_id' => $campaign->id,
            'invoice_number' => 'HM-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'status' => AdvertiserInvoiceStatus::Issued,
            'currency' => $campaign->currency,
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $subtotal + $tax,
            'issued_on' => $issued,
            'due_on' => now()->addDays($terms)->toDateString(),
            'line_items' => [[
                'description' => $campaign->name.' direct advertising campaign',
                'pricing_model' => $campaign->pricing_model->value,
                'quantity' => 1,
                'amount_minor' => $subtotal,
            ]],
        ]);
    }

    public function download(AdvertiserInvoice $invoice): Response
    {
        $invoice->loadMissing(['advertiser', 'billingProfile', 'campaign']);
        $html = view('advertiser.invoices.download', compact('invoice'))->render();

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$invoice->invoice_number.'.html"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
