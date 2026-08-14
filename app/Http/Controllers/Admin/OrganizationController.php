<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        return view('admin.organizations.index', ['organizations' => Organization::query()->latest()->paginate(25)]);
    }

    public function create(): View
    {
        return view('admin.organizations.form', ['organization' => new Organization]);
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $organization = Organization::create($this->validated($request));
        $audit->record('organization.created', $organization->id, $request->user(), $organization, newValues: $organization->only(['name', 'slug', 'type', 'status']));

        return redirect()->route('admin.organizations.edit', $organization)->with('status', 'Organization created.');
    }

    public function edit(Organization $organization): View
    {
        return view('admin.organizations.form', compact('organization'));
    }

    public function update(Request $request, Organization $organization, AuditRecorder $audit): RedirectResponse
    {
        $data = $this->validated($request, $organization);
        if ($organization->publisherApplication()->where('status', '!=', PublisherApplicationStatus::Approved->value)->exists()) {
            throw ValidationException::withMessages(['organization' => 'Use the Publisher Applications workflow while this public application is pending a final decision.']);
        }
        $before = $organization->only(['name', 'slug', 'type', 'status', 'support_email', 'internal_notes']);
        $organization->update($data);
        $audit->record('organization.updated', $organization->id, $request->user(), $organization, $before, $organization->only(array_keys($before)));

        return back()->with('status', 'Organization updated.');
    }

    public function destroy(Request $request, Organization $organization, SessionInvalidator $sessions, AuditRecorder $audit): RedirectResponse
    {
        abort_if($organization->type === OrganizationType::HorusMedia || $organization->id === $request->user()->organization_id, 422, 'The Horus or current organization cannot be deleted.');
        if ($organization->publisherApplication()->where('status', '!=', PublisherApplicationStatus::Approved->value)->exists()) {
            throw ValidationException::withMessages(['organization' => 'A pending public application must reach an explicit lifecycle decision before its canonical organization can be deleted.']);
        }
        $organization->users()->each(fn ($user) => $sessions->invalidate($user));
        $audit->record('organization.deleted', $organization->id, $request->user(), $organization, oldValues: $organization->only(['name', 'type']));
        $organization->delete();

        return redirect()->route('admin.organizations.index');
    }

    private function validated(Request $request, ?Organization $organization = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'alpha_dash', 'max:100', Rule::unique('organizations')->ignore($organization)],
            'type' => ['required', Rule::enum(OrganizationType::class)],
            'status' => ['required', 'in:PENDING,ACTIVE,SUSPENDED,CLOSED'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $requestedType = OrganizationType::from($data['type']);
        if ($organization?->type === OrganizationType::HorusMedia && $requestedType !== OrganizationType::HorusMedia) {
            throw ValidationException::withMessages(['type' => 'The Horus Media organization type cannot be changed.']);
        }

        $anotherHorusOrganizationExists = $requestedType === OrganizationType::HorusMedia
            && Organization::query()->where('type', OrganizationType::HorusMedia)->when($organization, fn ($query) => $query->whereKeyNot($organization->id))->exists();
        if ($anotherHorusOrganizationExists) {
            throw ValidationException::withMessages(['type' => 'Only one Horus Media organization may exist.']);
        }

        return $data;
    }
}
