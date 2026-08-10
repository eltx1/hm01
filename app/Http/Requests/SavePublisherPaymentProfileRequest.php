<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePublisherPaymentProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'beneficiary_name' => ['required', 'string', 'max:255'],
            'payment_method' => ['required', 'in:BANK_TRANSFER,PAYPAL,WISE,OTHER'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'country' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'account_reference' => ['nullable', 'string', 'max:255'],
            'routing_reference' => ['nullable', 'string', 'max:255'],
            'tax_identifier' => ['nullable', 'string', 'max:100'],
        ];
    }
}
