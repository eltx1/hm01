<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\FinancialReportingMethod;
use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\Organization;
use App\Models\Publisher;
use App\Models\ReportSource;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandReportService;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Reporting\MonetizationFinancialReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

final class DirectDemandAccountController extends Controller
{
    public function create(): View
    {
        return view('admin.demand.accounts.create', [
            'networks' => DemandNetwork::query()->where('is_enabled', true)->orderBy('name')->get(),
            'publishers' => Publisher::withoutGlobalScopes()->with('organization')->orderBy('display_name')->get(),
            'partners' => Organization::withoutGlobalScopes()->where('type', OrganizationType::Partner->value)->orderBy('name')->get(),
            'scopes' => DemandAccountScope::cases(),
            'modes' => DemandIntegrationMode::cases(),
            'statuses' => DemandApprovalStatus::cases(),
        ]);
    }

    public function store(Request $request, DemandAccountService $service): RedirectResponse
    {
        $data = $request->validate([
            'demand_network_id' => ['required', 'ulid', 'exists:demand_networks,id'],
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(DemandAccountScope::class)],
            'publisher_id' => ['nullable', 'required_if:scope,'.DemandAccountScope::Publisher->value, 'ulid', 'exists:publishers,id'],
            'partner_organization_id' => ['nullable', 'required_if:scope,'.DemandAccountScope::McmPartner->value, 'ulid', 'exists:organizations,id'],
            'integration_mode' => ['required', Rule::enum(DemandIntegrationMode::class)],
            'approval_status' => ['nullable', Rule::enum(DemandApprovalStatus::class)],
            'account_identifier' => ['nullable', 'string', 'max:255'],
            'reporting_method' => ['nullable', Rule::in(['API', 'CSV'])],
            'default_render_timeout_ms' => ['nullable', 'integer', 'between:500,10000'],
            'approved_script_origins_text' => ['nullable', 'string', 'max:10000'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
            'fallback_priority' => ['required', 'integer', 'between:0,10000'],
            'configuration_json' => ['nullable', 'json'],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $scope = DemandAccountScope::from((string) $data['scope']);
        $data['publisher_id'] = $scope === DemandAccountScope::Publisher ? ($data['publisher_id'] ?? null) : null;
        $data['partner_organization_id'] = $scope === DemandAccountScope::McmPartner ? ($data['partner_organization_id'] ?? null) : null;
        $data['configuration'] = $this->accountConfiguration($data, $this->decodeConfiguration($data['configuration_json'] ?? null));

        unset($data['configuration_json'], $data['reporting_method'], $data['default_render_timeout_ms'], $data['approved_script_origins_text']);

        $account = $service->create($data, $request->user());

        return redirect()->route('admin.demand.accounts.show', $account)
            ->with('status', "Demand account {$account->name} created.");
    }

    public function show(
        DemandAccount $demandAccount,
        DemandReportService $reports,
        MonetizationFinancialReadinessService $financialReadiness,
    ): View {
        $demandAccount->load([
            'network',
            'publisher.organization',
            'partnerOrganization',
            'sites.site',
            'credentials',
            'financialBinding.source',
        ]);

        return view('admin.demand.accounts.show', [
            'account' => $demandAccount,
            'summary' => $reports->summary($demandAccount),
            'financial' => $financialReadiness->status($demandAccount),
            'modes' => DemandIntegrationMode::cases(),
            'statuses' => DemandApprovalStatus::cases(),
            'reportSources' => ReportSource::query()->where('is_enabled', true)->orderBy('name')->get(),
            'financialMethods' => FinancialReportingMethod::cases(),
        ]);
    }

    public function update(
        Request $request,
        DemandAccount $demandAccount,
        DemandAccountService $service,
        SiteConfigPublisher $publisher,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'integration_mode' => ['required', Rule::enum(DemandIntegrationMode::class)],
            'approval_status' => ['required', Rule::enum(DemandApprovalStatus::class)],
            'account_identifier' => ['nullable', 'string', 'max:255'],
            'reporting_method' => ['nullable', Rule::in(['API', 'CSV'])],
            'default_render_timeout_ms' => ['nullable', 'integer', 'between:500,10000'],
            'approved_script_origins_text' => ['nullable', 'string', 'max:10000'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
            'fallback_priority' => ['required', 'integer', 'between:0,10000'],
            'configuration_json' => ['nullable', 'json'],
            'is_enabled' => ['required', 'boolean'],
            'is_default' => ['required', 'boolean'],
        ]);

        $data['scope'] = $demandAccount->scope->value;
        $data['publisher_id'] = $demandAccount->publisher_id;
        $data['partner_organization_id'] = $demandAccount->partner_organization_id;
        $data['configuration'] = $this->accountConfiguration($data, $this->decodeConfiguration($data['configuration_json'] ?? null));

        unset($data['configuration_json'], $data['reporting_method'], $data['default_render_timeout_ms'], $data['approved_script_origins_text']);

        DB::transaction(function () use ($service, $demandAccount, $data, $request): void {
            $service->update($demandAccount, $data, $request->user());
        });

        $demandAccount->load('sites.site');
        foreach ($demandAccount->sites as $mapping) {
            if ($mapping->site) {
                $publisher->publishActiveProduction($mapping->site, $request->user());
            }
        }

        return back()->with('status', 'Demand account updated and affected website configurations published.');
    }

    private function accountConfiguration(array $data, array $configuration): array
    {
        if (! empty($data['reporting_method'])) {
            $configuration['reporting_method'] = $data['reporting_method'];
        } else {
            unset($configuration['reporting_method']);
        }

        if (! empty($data['default_render_timeout_ms'])) {
            $configuration['render_timeout_ms'] = (int) $data['default_render_timeout_ms'];
        } else {
            unset($configuration['render_timeout_ms']);
        }

        $configuration['allowed_script_origins'] = $this->parseOrigins($data['approved_script_origins_text'] ?? null);

        return $configuration;
    }

    private function parseOrigins(?string $value): array
    {
        $origins = collect(preg_split('/\R+/', (string) $value) ?: [])
            ->map(fn ($origin) => strtolower(rtrim(trim((string) $origin), '/')))
            ->filter()
            ->unique()
            ->values();

        if ($origins->count() > 20) {
            throw ValidationException::withMessages([
                'approved_script_origins_text' => 'No more than 20 approved script origins may be configured.',
            ]);
        }

        foreach ($origins as $origin) {
            $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            if ($scheme !== 'https' || ! $host || filter_var($origin, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages([
                    'approved_script_origins_text' => "Approved script origin must be a valid HTTPS URL: {$origin}",
                ]);
            }
            if ($host === 'app.horusmedia.net' || str_ends_with($host, '.app.horusmedia.net')) {
                throw ValidationException::withMessages([
                    'approved_script_origins_text' => 'Provider script origins cannot use the Horus control-plane origin.',
                ]);
            }
        }

        return $origins->all();
    }

    private function decodeConfiguration(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['configuration_json' => 'Configuration must contain valid JSON.']);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['configuration_json' => 'Configuration JSON must be an object or array.']);
        }

        return $decoded;
    }
}
