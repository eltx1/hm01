@props(['profile' => null, 'action', 'canEdit' => true, 'cancelHref' => null])
@php
    $status = $profile?->verification_status ?? \App\Enums\PublisherPaymentProfileStatus::Incomplete;
    $methods = [
        'BANK_TRANSFER' => ['label' => 'Bank transfer', 'detail' => 'Bank account / IBAN', 'icon' => 'bank'],
        'PAYPAL' => ['label' => 'PayPal', 'detail' => 'PayPal email', 'icon' => 'mail'],
        'WISE' => ['label' => 'Wise', 'detail' => 'Wise account details', 'icon' => 'transfer'],
        'OTHER' => ['label' => 'Other', 'detail' => 'Agreed with Finance', 'icon' => 'wallet'],
    ];
    $selectedMethod = old('payment_method', $profile?->payment_method ?: 'BANK_TRANSFER');
    $country = old('country', $profile?->country ?: '');
    $countries = config('countries', []);
    if ($country && ! isset($countries[$country])) $countries[$country] = $country;
    $statusCopy = match ($status) {
        \App\Enums\PublisherPaymentProfileStatus::Verified => ['Payment details verified', 'This destination has been reviewed. Changing it will require a new review before payouts.'],
        \App\Enums\PublisherPaymentProfileStatus::PendingVerification => ['Your details are in review', 'Your destination is saved. Our Finance team will review it before payouts.'],
        \App\Enums\PublisherPaymentProfileStatus::Rejected => ['An update is needed', 'Please check the Finance feedback and update your payment details.'],
        \App\Enums\PublisherPaymentProfileStatus::NeedsUpdate => ['A new review is needed', 'Your payment details changed. Finance needs to review this destination again.'],
        default => ['Set up your payment details', 'Add your account details so Finance can review where your earnings should be sent.'],
    };
@endphp
<div class="ui-page">
    <div class="ui-page-intro">
        <div><h2>Choose how you get paid</h2><p>A clear, secure destination for your publisher earnings.</p></div>
        <x-status-badge :status="$status" />
    </div>
    <div class="ui-settings-layout">
        @if($canEdit)
        <form method="POST" action="{{ $action }}" class="ui-form-surface" autocomplete="off" data-payment-profile-form data-saved-method="{{ $profile?->payment_method }}">
            @csrf @method('PUT')
            <x-form-section number="01" title="Payment method" description="Select the destination you want Finance to use.">
                <fieldset class="ui-choice-grid" @error('payment_method')aria-describedby="payment-method-error"@enderror>
                    <legend class="sr-only">Payment method</legend>
                    @foreach($methods as $value => $method)
                    <label class="ui-choice">
                        <input type="radio" name="payment_method" value="{{ $value }}" @checked($selectedMethod === $value) required>
                        <span class="ui-choice-icon"><x-ui-icon :name="$method['icon']" /></span>
                        <strong>{{ $method['label'] }}</strong><small>{{ $method['detail'] }}</small>
                    </label>
                    @endforeach
                </fieldset>
                @error('payment_method')<p id="payment-method-error" class="field-error" role="alert">{{ $message }}</p>@enderror
            </x-form-section>
            <x-form-section number="02" title="Account holder" description="Use the details registered with your bank or payment provider.">
                <div class="ui-fields">
                    <x-form-field name="beneficiary_name" label="Account holder or business name" :value="old('beneficiary_name', $profile?->beneficiary_name)" required maxlength="255" autocomplete="organization" placeholder="Full legal name" />
                    <x-form-field name="country" label="Country or territory" as="select" :value="$country" :options="$countries" required placeholder="Select a country" autocomplete="country" />
                    <x-form-field name="currency" label="Payout currency" :value="old('currency', $profile?->currency ?: 'USD')" required maxlength="3" minlength="3" pattern="[A-Za-z]{3}" placeholder="USD" list="payout-currencies" help="Three-letter currency code, for example USD." />
                    <datalist id="payout-currencies"><option value="USD">US Dollar</option><option value="EUR">Euro</option><option value="GBP">British Pound</option><option value="AED">UAE Dirham</option><option value="EGP">Egyptian Pound</option></datalist>
                    <x-form-field name="billing_address" label="Billing address" :value="old('billing_address', $profile?->billing_address)" maxlength="500" autocomplete="street-address" placeholder="Street, city and postal code" help="Optional, unless requested by Finance." />
                </div>
            </x-form-section>
            <x-form-section number="03" title="Payment destination" description="Saved account details stay private. Enter a value only when adding or replacing it.">
                <div class="ui-fields">
                    <x-form-field name="account_reference" label="Account or payment reference" value="" maxlength="255" autocomplete="new-password" :help="$profile?->maskedAccountReference() ? 'Saved reference: '.$profile->maskedAccountReference().'. Leave blank to keep it when using the same payment method.' : 'Add a bank account / IBAN, PayPal email, or your provider’s payment reference. Needed before Finance can verify your details.'" />
                    <x-form-field name="routing_reference" label="Routing number or SWIFT / BIC" value="" maxlength="255" autocomplete="new-password" help="For bank details when needed. To replace the account, include its routing details again." />
                </div>
                <p class="ui-inline-note" data-payment-change-note @if(! $profile || $selectedMethod === $profile->payment_method)hidden@endif>Changing the payment method? Enter its account or payment reference, even if you already have a saved destination.</p>
                <details class="ui-disclosure" @if($errors->has('tax_identifier'))open@endif>
                    <summary>Tax information <span class="muted">· optional</span></summary>
                    <x-form-field name="tax_identifier" label="Tax identification number" value="" maxlength="100" autocomplete="new-password" help="Provide this only when requested. Leave blank to keep any tax information already saved." />
                </details>
                @if($errors->any())<p class="ui-inline-note">For your privacy, account, routing and tax values are not restored after a validation error. Please enter any new values again.</p>@endif
            </x-form-section>
            <x-form-actions help="Your details are reviewed before payouts." :cancel-href="$cancelHref">
                <button class="hm-button-primary" type="submit" data-submitting-label="Saving…">Save payment method</button>
            </x-form-actions>
        </form>
        @else
        <div class="ui-form-surface">
            <x-form-section title="Your payment details" description="Your role can view this payment profile but cannot change it.">
                <dl class="ui-readonly-grid">
                    @foreach(['Account holder' => $profile?->beneficiary_name, 'Payment method' => $methods[$profile?->payment_method]['label'] ?? null, 'Country or territory' => $countries[$profile?->country] ?? $profile?->country, 'Currency' => $profile?->currency, 'Account reference' => $profile?->maskedAccountReference(), 'Billing address' => $profile?->billing_address] as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value ?: 'Not added yet' }}</dd></div>
                    @endforeach
                </dl>
            </x-form-section>
        </div>
        @endif
        <aside class="ui-form-aside" aria-label="Payment profile summary">
            <section class="ui-aside-card">
                <span class="ui-aside-icon"><x-ui-icon name="wallet" /></span>
                <h2>{{ $statusCopy[0] }}</h2><p>{{ $statusCopy[1] }}</p>
                @if($profile?->verification_reason)<div class="ui-inline-note"><strong>Finance feedback</strong><p>{{ $profile->verification_reason }}</p></div>@endif
                <dl class="ui-detail-list">
                    <div><dt>Saved payment method</dt><dd>{{ $methods[$profile?->payment_method]['label'] ?? 'Not added yet' }}</dd></div>
                    <div><dt>Saved account</dt><dd>{{ $profile?->maskedAccountReference() ?: 'Not added yet' }}</dd></div>
                    @if($profile?->currency)<div><dt>Saved payout currency</dt><dd>{{ $profile->currency }}</dd></div>@endif
                </dl>
            </section>
            <section class="ui-aside-card">
                <span class="ui-aside-icon"><x-ui-icon name="shield" /></span>
                <h2>Before you save</h2>
                <ul class="ui-guidance-list"><li>Match the account holder name to your payment provider’s records.</li><li>Check the currency and account reference carefully.</li><li>Sensitive details are encrypted. Only a masked account reference is shown after saving.</li></ul>
            </section>
        </aside>
    </div>
</div>
