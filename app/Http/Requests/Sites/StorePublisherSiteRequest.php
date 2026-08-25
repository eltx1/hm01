<?php

namespace App\Http\Requests\Sites;

use App\Models\Publisher;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublisherSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $domain = (string) $this->input('primary_domain');
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST);

        $this->merge([
            'primary_domain' => strtolower(rtrim((string) $host, '.')),
            'country' => strtoupper(trim((string) $this->input('country'))),
        ]);
    }

    public function rules(): array
    {
        $publisher = $this->publisherAccount();

        return [
            'display_name' => ['required', 'string', 'max:255'],
            'primary_domain' => [
                'required',
                'max:255',
                'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
                Rule::unique('sites')->where('publisher_id', $publisher->id),
            ],
            'content_category' => ['required', 'string', Rule::in(['NEWS', 'ENTERTAINMENT', 'SPORTS', 'TECHNOLOGY', 'LIFESTYLE', 'BUSINESS', 'OTHER'])],
            'country' => ['required', 'string', 'size:2'],
        ];
    }

    public function publisherAccount(): Publisher
    {
        $publisher = $this->route('publisher');

        if ($publisher instanceof Publisher) {
            return $publisher;
        }

        return Publisher::query()
            ->where('organization_id', $this->user()->organization_id)
            ->firstOrFail();
    }

    public function sitePayload(): array
    {
        $data = $this->validated();

        return $data + [
            'language' => 'en',
            'main_traffic_countries' => [$data['country']],
            'estimated_monthly_pageviews' => 0,
            'estimated_monthly_users' => 0,
            'current_monetization_providers' => [],
            'current_gam_network_code' => null,
            'current_adsense_status' => null,
            'current_adx_status' => null,
            'prebid_enabled' => false,
            'native_demand_enabled' => false,
        ];
    }
}
