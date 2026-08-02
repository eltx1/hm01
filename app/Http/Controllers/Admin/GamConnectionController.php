<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GamConnectionType;
use App\Enums\GamCredentialType;
use App\Enums\ServingMode;
use App\Http\Controllers\Controller;
use App\Models\GamConnection;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Gam\GamConnectionService;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use JsonException;

class GamConnectionController extends Controller
{
    public function index(GamConnectionResolver $resolver): View
    {
        $connections = GamConnection::withoutGlobalScopes()
            ->withCount(['sites', 'networks', 'operations', 'errors'])
            ->with(['credential', 'networks' => fn ($query) => $query->where('is_current', true)])
            ->latest()
            ->paginate(25);

        return view('admin.gam.connections.index', [
            'connections' => $connections,
            'primaryHorus' => GamConnection::withoutGlobalScopes()->where('type', GamConnectionType::HorusGam)->where('is_primary', true)->first(),
            'unresolvedHorusSites' => Site::withoutGlobalScopes()->where('serving_mode', 'HORUS_GAM')->get()->filter(fn (Site $site) => $resolver->resolve($site) === null)->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.gam.connections.form', [
            'connection' => new GamConnection([
                'type' => GamConnectionType::HorusGam,
                'credential_type' => GamCredentialType::ServiceAccount,
                'driver' => 'REST',
                'is_enabled' => true,
                'dry_run_default' => true,
            ]),
            'organizations' => Organization::withoutGlobalScopes()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, GamConnectionService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        $connection = $service->create($this->validated($request, true), $request->user());
        $this->republishAffectedSites($connection, $publisher, $request->user());

        return redirect()->route('admin.gam.connections.show', $connection)->with('status', 'Google Ad Manager connection created. Dry-run is enabled by default.');
    }

    public function show(GamConnection $gamConnection): View
    {
        $gamConnection->load([
            'credential', 'networks', 'permissions',
            'operations' => fn ($query) => $query->latest()->limit(20),
            'syncRuns' => fn ($query) => $query->latest()->limit(10),
            'errors' => fn ($query) => $query->whereNull('resolved_at')->latest()->limit(20),
            'sites.publisher',
        ]);

        return view('admin.gam.connections.show', [
            'connection' => $gamConnection,
            'sites' => Site::withoutGlobalScopes()->with('publisher')->orderBy('display_name')->get(),
        ]);
    }

    public function edit(GamConnection $gamConnection): View
    {
        return view('admin.gam.connections.form', [
            'connection' => $gamConnection->load('credential'),
            'organizations' => Organization::withoutGlobalScopes()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, GamConnection $gamConnection, GamConnectionService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        $previousType = $gamConnection->type;
        $connection = $service->update($gamConnection, $this->validated($request, false), $request->user());
        $this->republishAffectedSites($connection, $publisher, $request->user(), [$previousType]);

        return redirect()->route('admin.gam.connections.show', $gamConnection)->with('status', 'Google Ad Manager connection updated and active affected websites were queued automatically.');
    }

    public function test(Request $request, GamConnection $gamConnection, GamConnectionService $service): RedirectResponse
    {
        $result = $service->test($gamConnection, $request->user());

        return back()->with($result->success ? 'status' : 'error', $result->success
            ? 'Connection test succeeded and network metadata was synchronized.'
            : "Connection test failed: {$result->errorMessage}");
    }

    public function primary(Request $request, GamConnection $gamConnection, GamConnectionService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        $connection = $service->setPrimary($gamConnection, $request->user());
        $this->republishAffectedSites($connection, $publisher, $request->user());

        return back()->with('status', 'Primary HORUS_GAM connection selected and active fallback websites were queued automatically.');
    }

    public function assignSite(Request $request, GamConnection $gamConnection, GamConnectionService $service, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'ulid', 'exists:sites,id'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $site = Site::withoutGlobalScopes()->findOrFail($data['site_id']);
        $service->assignToSite($site, $gamConnection, $request->user(), $data['reason']);
        $version = $publisher->publishActiveProduction($site->refresh(), $request->user());

        $delivery = $version
            ? 'production configuration v'.$version->version.' was queued automatically'
            : 'the production configuration will publish automatically when the website is activated';

        return back()->with('status', 'The website now uses the selected GAM connection and '.$delivery.'. Other websites were not changed.');
    }

    private function republishAffectedSites(
        GamConnection $connection,
        SiteConfigPublisher $publisher,
        User $actor,
        array $additionalTypes = [],
    ): void {
        $types = collect([$connection->type, ...$additionalTypes])->unique(fn (GamConnectionType $type) => $type->value);
        $sites = Site::withoutGlobalScopes()
            ->where(function ($query) use ($connection, $types): void {
                $query->where('gam_connection_id', $connection->id);
                foreach ($types as $type) {
                    $query->orWhere(function ($fallback) use ($connection, $type): void {
                        $fallback->whereNull('gam_connection_id')->where('serving_mode', match ($type) {
                            GamConnectionType::HorusGam => ServingMode::HorusGam->value,
                            GamConnectionType::McmPartnerGam => ServingMode::McmPartnerGam->value,
                            GamConnectionType::PublisherGam => ServingMode::PublisherGam->value,
                        });
                        if ($type === GamConnectionType::PublisherGam) {
                            $fallback->where('organization_id', $connection->organization_id);
                        }
                    });
                }
            })
            ->get();

        foreach ($sites as $site) {
            $publisher->publishActiveProduction($site, $actor);
        }
    }

    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'organization_id' => ['required', 'ulid', 'exists:organizations,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(GamConnectionType::class)],
            'credential_type' => ['required', Rule::enum(GamCredentialType::class)],
            'driver' => ['required', Rule::in(['REST'])],
            'network_code' => ['nullable', 'string', 'max:64', 'regex:/^\d+$/'],
            'application_name' => ['required', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_enabled' => ['sometimes', 'boolean'],
            'dry_run_default' => ['sometimes', 'boolean'],
            'credential_reference' => [$creating ? 'required' : 'nullable', 'string', 'max:1000'],
            'client_email_hint' => ['nullable', 'email', 'max:255'],
            'oauth_client_id_hint' => ['nullable', 'string', 'max:255'],
            'scopes_text' => ['nullable', 'string', 'max:2000'],
            'configuration_json' => ['nullable', 'string', 'max:20000'],
        ]);

        $data['is_primary'] = $request->boolean('is_primary');
        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['dry_run_default'] = $request->boolean('dry_run_default');
        $data['scopes'] = array_values(array_filter(array_map('trim', explode(',', (string) ($data['scopes_text'] ?? config('gam.oauth.scope'))))));
        unset($data['scopes_text']);

        try {
            $data['configuration'] = filled($data['configuration_json'] ?? null)
                ? json_decode($data['configuration_json'], true, 512, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            abort(422, 'Connection configuration must be valid JSON.');
        }
        unset($data['configuration_json']);

        if (! $creating && blank($data['credential_reference'] ?? null)) {
            unset($data['credential_reference']);
        }

        return $data;
    }
}
