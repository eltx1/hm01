<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\Advertiser;
use App\Models\Organization;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdvertiserController extends Controller
{
    public function index(): View
    {
        return view('admin.accounts.index', ['accounts' => Advertiser::withoutGlobalScopes()->with('organization')->latest()->paginate(25), 'kind' => 'advertiser']);
    }

    public function create(): View
    {
        return view('admin.accounts.form', ['account' => new Advertiser, 'kind' => 'advertiser']);
    }

    public function edit(Advertiser $advertiser): View
    {
        return view('admin.accounts.form', ['account' => $advertiser->load('contacts'), 'kind' => 'advertiser']);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $advertiser = DB::transaction(function () use ($data): Advertiser {
            $organization = Organization::create(['name' => $data['display_name'], 'slug' => $data['organization_slug'], 'type' => OrganizationType::Advertiser, 'status' => $data['status'], 'dashboard_title' => $data['dashboard_title'] ?? null, 'primary_color' => $data['primary_color'] ?? null, 'support_email' => $data['billing_email'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null]);

            return Advertiser::withoutGlobalScopes()->create(array_merge($data, ['organization_id' => $organization->id]));
        });
        $audit->record('advertiser.created', $advertiser->organization_id, $request->user(), $advertiser, newValues: $advertiser->only(['legal_name', 'display_name', 'status']));

        return redirect()->route('admin.advertisers.edit', $advertiser)->with('status', 'Advertiser created.');
    }

    public function update(Request $request, Advertiser $advertiser, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request, $advertiser);
        $before = $advertiser->only(['legal_name', 'display_name', 'status', 'billing_email', 'dashboard_title', 'primary_color', 'internal_notes']);
        DB::transaction(function () use ($advertiser, $data): void {
            $advertiser->update($data);
            $advertiser->organization->update(['name' => $data['display_name'], 'slug' => $data['organization_slug'], 'status' => $data['status'], 'dashboard_title' => $data['dashboard_title'] ?? null, 'primary_color' => $data['primary_color'] ?? null, 'support_email' => $data['billing_email'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null]);
        });
        $audit->record('advertiser.updated', $advertiser->organization_id, $request->user(), $advertiser, $before, $advertiser->only(array_keys($before)));

        return back()->with('status', 'Advertiser updated.');
    }

    public function destroy(Request $request, Advertiser $advertiser, SessionInvalidator $sessions, AuditRecorder $audit): RedirectResponse
    {
        $advertiser->organization->users()->each(fn ($user) => $sessions->invalidate($user));
        $audit->record('advertiser.deleted', $advertiser->organization_id, $request->user(), $advertiser, oldValues: $advertiser->only(['legal_name', 'display_name']));
        DB::transaction(function () use ($advertiser): void {
            $advertiser->delete();
            $advertiser->organization->delete();
        });

        return redirect()->route('admin.advertisers.index');
    }

    private function validated(Request $request, ?Advertiser $advertiser = null): array
    {
        return $request->validate([
            'legal_name' => ['required', 'string', 'max:255'], 'display_name' => ['required', 'string', 'max:255'],
            'organization_slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('organizations', 'slug')->ignore($advertiser?->organization_id)],
            'status' => ['required', 'in:PENDING,ACTIVE,SUSPENDED,CLOSED'], 'billing_email' => ['nullable', 'email'],
            'dashboard_title' => ['nullable', 'string', 'max:100'], 'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
