<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Publisher;
use App\Models\PublisherStatement;
use App\Models\ThothSetting;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use App\Services\Reporting\UnifiedReportService;
use App\Services\SupplyChain\SupplyChainInvariantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublisherController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->string('status')->upper()->value();
        $accounts = Publisher::withoutGlobalScopes()->with('organization')
            ->when(in_array($status, ['PENDING', 'ACTIVE', 'SUSPENDED', 'CLOSED'], true), fn ($query) => $query->where('status', $status))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.accounts.index', ['accounts' => $accounts, 'kind' => 'publisher', 'activeStatus' => $status]);
    }

    public function create(): View
    {
        return view('admin.accounts.form', ['account' => new Publisher, 'kind' => 'publisher']);
    }

    public function edit(Publisher $publisher): View
    {
        return view('admin.accounts.form', ['account' => $publisher->load('contacts'), 'kind' => 'publisher']);
    }

    public function show(Request $request, Publisher $publisher, UnifiedReportService $reports): View
    {
        $publisher->load([
            'organization.users.roles',
            'contacts',
            'sites' => fn ($query) => $query->with(['domains', 'gamConnection', 'servingSettings', 'siteConfig'])->latest(),
            'contracts' => fn ($query) => $query->latest(),
            'paymentProfile',
            'qualityProfiles' => fn ($query) => $query->latest('version'),
            'qualityReviewRuns' => fn ($query) => $query->latest()->limit(20),
            'qualityDecisions' => fn ($query) => $query->latest()->limit(20),
        ]);

        $canViewFinance = $request->user()->hasPermission('finance.publisher.view')
            || $request->user()->hasPermission('reporting.admin.view');

        return view('admin.publishers.show', [
            'publisher' => $publisher,
            'reporting' => $canViewFinance ? $reports->publisherSummary($publisher) : null,
            'statements' => $canViewFinance
                ? PublisherStatement::withoutGlobalScopes()->where('publisher_id', $publisher->id)->with('period')->latest()->limit(12)->get()
                : collect(),
            'auditEvents' => $request->user()->hasPermission('audit.view')
                ? AuditLog::query()->where('organization_id', $publisher->organization_id)->latest()->limit(30)->get()
                : collect(),
            'thothSettings' => ThothSetting::current(),
        ]);
    }

    public function store(Request $request, AuditRecorder $audit, SupplyChainInvariantService $identities): RedirectResponse
    {
        $data = $this->validated($request);
        $data = array_merge($data, $identities->publisherIdentityAttributes(null, $data['business_domain'] ?? null));
        $publisher = DB::transaction(function () use ($data): Publisher {
            $organization = Organization::create(['name' => $data['display_name'], 'slug' => $data['organization_slug'], 'type' => OrganizationType::Publisher, 'status' => $data['status'], 'dashboard_title' => $data['dashboard_title'] ?? null, 'primary_color' => $data['primary_color'] ?? null, 'support_email' => $data['billing_email'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null]);

            return Publisher::withoutGlobalScopes()->create(array_merge($data, ['organization_id' => $organization->id]));
        });
        $audit->record('publisher.created', $publisher->organization_id, $request->user(), $publisher, newValues: $publisher->only(['legal_name', 'display_name', 'business_domain', 'supply_chain_review_status', 'status']));

        return redirect()->route('admin.publishers.edit', $publisher)->with('status', 'Publisher created.');
    }

    public function update(Request $request, Publisher $publisher, AuditRecorder $audit, SupplyChainInvariantService $identities): RedirectResponse
    {
        $data = $this->validated($request, $publisher);
        $businessDomain = array_key_exists('business_domain', $data) ? $data['business_domain'] : $publisher->business_domain;
        $data = array_merge($data, $identities->publisherIdentityAttributes($publisher, $businessDomain));
        $before = $publisher->only(['legal_name', 'display_name', 'business_domain', 'supply_chain_review_status', 'supply_chain_reviewed_at', 'supply_chain_reviewed_by', 'status', 'billing_email', 'dashboard_title', 'primary_color', 'internal_notes']);
        DB::transaction(function () use ($publisher, $data): void {
            $publisher->update($data);
            $publisher->organization->update(['name' => $data['display_name'], 'slug' => $data['organization_slug'], 'status' => $data['status'], 'dashboard_title' => $data['dashboard_title'] ?? null, 'primary_color' => $data['primary_color'] ?? null, 'support_email' => $data['billing_email'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null]);
        });
        $audit->record('publisher.updated', $publisher->organization_id, $request->user(), $publisher, $before, $publisher->only(array_keys($before)));

        return back()->with('status', 'Publisher updated.');
    }

    public function destroy(Request $request, Publisher $publisher, SessionInvalidator $sessions, AuditRecorder $audit): RedirectResponse
    {
        $publisher->organization->users()->each(fn ($user) => $sessions->invalidate($user));
        $audit->record('publisher.deleted', $publisher->organization_id, $request->user(), $publisher, oldValues: $publisher->only(['legal_name', 'display_name']));
        DB::transaction(function () use ($publisher): void {
            $publisher->delete();
            $publisher->organization->delete();
        });

        return redirect()->route('admin.publishers.index');
    }

    private function validated(Request $request, ?Publisher $publisher = null): array
    {
        return $request->validate([
            'legal_name' => ['required', 'string', 'max:255'], 'display_name' => ['required', 'string', 'max:255'],
            'business_domain' => ['nullable', 'string', 'max:253'],
            'organization_slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('organizations', 'slug')->ignore($publisher?->organization_id)],
            'status' => ['required', 'in:PENDING,ACTIVE,SUSPENDED,CLOSED'], 'billing_email' => ['nullable', 'email'],
            'dashboard_title' => ['nullable', 'string', 'max:100'], 'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
