<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Publisher;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublisherController extends Controller
{
    public function index(): View
    {
        return view('admin.accounts.index', ['accounts' => Publisher::withoutGlobalScopes()->with('organization')->latest()->paginate(25), 'kind' => 'publisher']);
    }

    public function create(): View
    {
        return view('admin.accounts.form', ['account' => new Publisher, 'kind' => 'publisher']);
    }

    public function edit(Publisher $publisher): View
    {
        return view('admin.accounts.form', ['account' => $publisher->load('contacts'), 'kind' => 'publisher']);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $publisher = DB::transaction(function () use ($data): Publisher {
            $organization = Organization::create(['name' => $data['display_name'], 'slug' => $data['organization_slug'], 'type' => OrganizationType::Publisher, 'status' => $data['status'], 'dashboard_title' => $data['dashboard_title'] ?? null, 'primary_color' => $data['primary_color'] ?? null, 'support_email' => $data['billing_email'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null]);

            return Publisher::withoutGlobalScopes()->create(array_merge($data, ['organization_id' => $organization->id]));
        });
        $audit->record('publisher.created', $publisher->organization_id, $request->user(), $publisher, newValues: $publisher->only(['legal_name', 'display_name', 'status']));

        return redirect()->route('admin.publishers.edit', $publisher)->with('status', 'Publisher created.');
    }

    public function update(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request, $publisher);
        $before = $publisher->only(['legal_name', 'display_name', 'status', 'billing_email', 'dashboard_title', 'primary_color', 'internal_notes']);
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
            'organization_slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('organizations', 'slug')->ignore($publisher?->organization_id)],
            'status' => ['required', 'in:PENDING,ACTIVE,SUSPENDED,CLOSED'], 'billing_email' => ['nullable', 'email'],
            'dashboard_title' => ['nullable', 'string', 'max:100'], 'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
