<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BrandingController extends Controller
{
    public function edit(Request $request, ?Organization $organization = null): View
    {
        $organization ??= $request->user()->organization;
        $this->authorizeOrganization($request, $organization);

        return view('account.branding', compact('organization'));
    }

    public function update(Request $request, AuditRecorder $audit, ?Organization $organization = null): RedirectResponse
    {
        $organization ??= $request->user()->organization;
        $this->authorizeOrganization($request, $organization);
        $data = $request->validate([
            'dashboard_title' => ['nullable', 'string', 'max:100'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=64,min_height=64,max_width=2000,max_height=2000'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);
        $before = $organization->only(['dashboard_title', 'primary_color', 'support_email', 'logo_path']);

        if ($request->boolean('remove_logo') && $organization->logo_path) {
            Storage::disk('public')->delete($organization->logo_path);
            $data['logo_path'] = null;
        }
        if ($request->hasFile('logo')) {
            if ($organization->logo_path) {
                Storage::disk('public')->delete($organization->logo_path);
            }
            $data['logo_path'] = $request->file('logo')->store("branding/{$organization->id}", 'public');
        }
        unset($data['logo'], $data['remove_logo']);
        $organization->update($data);
        $accountBranding = collect($data)
            ->only(['dashboard_title', 'primary_color', 'logo_path'])
            ->when(array_key_exists('support_email', $data), fn ($values) => $values->put('billing_email', $data['support_email']))
            ->all();
        $organization->publisher()->update($accountBranding);
        $organization->advertiser()->update($accountBranding);
        $audit->record('organization.branding.updated', $organization->id, $request->user(), $organization, $before, $organization->only(array_keys($before)));

        return back()->with('status', 'Branding updated.');
    }

    private function authorizeOrganization(Request $request, Organization $organization): void
    {
        abort_unless($request->user()->isHorusAdministrator() || $request->user()->organization_id === $organization->id, 403);
    }
}
