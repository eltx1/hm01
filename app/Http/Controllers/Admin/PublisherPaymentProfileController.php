<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\PublisherPaymentProfile;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublisherPaymentProfileController extends Controller
{
    public function edit(Publisher $publisher): View
    {
        return view('admin.publishers.payment-profile', ['publisher' => $publisher, 'profile' => $publisher->paymentProfile]);
    }

    public function update(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $profile = $publisher->paymentProfile;
        $data = $request->validate([
            'beneficiary_name' => ['required', 'string', 'max:255'], 'payment_method' => ['required', 'in:BANK_TRANSFER,PAYPAL,WISE,OTHER'],
            'currency' => ['required', 'string', 'size:3'], 'country' => ['required', 'string', 'size:2'], 'billing_address' => ['nullable', 'string', 'max:500'],
            'account_reference' => ['nullable', 'string', 'max:255'], 'routing_reference' => ['nullable', 'string', 'max:255'], 'tax_identifier' => ['nullable', 'string', 'max:100'], 'is_verified' => ['sometimes', 'boolean'],
        ]);
        $before = $profile?->only(['beneficiary_name', 'payment_method', 'currency', 'country', 'account_last_four', 'is_verified']) ?? [];
        $values = [
            'organization_id' => $publisher->organization_id, 'beneficiary_name' => $data['beneficiary_name'], 'payment_method' => $data['payment_method'],
            'currency' => strtoupper($data['currency']), 'country' => strtoupper($data['country']), 'billing_address' => $data['billing_address'] ?? null, 'is_verified' => (bool) ($data['is_verified'] ?? false),
        ];
        if (! empty($data['account_reference'])) {
            $values['payment_details'] = ['account_reference' => $data['account_reference'], 'routing_reference' => $data['routing_reference'] ?? null];
            $values['account_last_four'] = substr(preg_replace('/\s+/', '', $data['account_reference']), -4);
        }
        if (! empty($data['tax_identifier'])) {
            $values['tax_identifier'] = $data['tax_identifier'];
        }
        $profile = PublisherPaymentProfile::updateOrCreate(['publisher_id' => $publisher->id], $values);
        $audit->record('publisher.payment_profile.updated', $publisher->organization_id, $request->user(), $profile, $before, $profile->only(['beneficiary_name', 'payment_method', 'currency', 'country', 'account_last_four', 'is_verified']));

        return back()->with('status', 'Payment profile updated. Sensitive values were not logged.');
    }
}
