<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplyChainReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformAdsTxtRecord;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\SupplyChain\PlatformAdsTxtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PlatformAdsTxtController extends Controller
{
    public function index(PlatformAdsTxtService $service, AdsTxtComplianceService $compliance): View
    {
        $records = PlatformAdsTxtRecord::with(['reviewer', 'creator', 'updater'])
            ->orderBy('advertising_system_domain')->orderBy('publisher_account_id')->get();
        $previewSites = Site::withoutGlobalScopes()->with('publisher')->orderBy('primary_domain')->limit(10)->get();

        return view('admin.compliance.ads-txt.master', [
            'records' => $records,
            'impactedSiteCount' => $service->impactedSiteCount(),
            'previewSites' => $previewSites,
            'previews' => $previewSites->mapWithKeys(fn (Site $site): array => [$site->id => $compliance->canonical($site)]),
            'auditEvents' => AuditLog::query()->where('event', 'like', 'supply_chain.platform_ads_txt.%')->latest()->limit(100)->get(),
        ]);
    }

    public function store(Request $request, PlatformAdsTxtService $service): RedirectResponse
    {
        $record = $service->create($this->validated($request), $request->user());
        return back()->with('status', 'Platform master authorization created disabled and awaiting review: '.$record->raw_record);
    }

    public function update(Request $request, PlatformAdsTxtRecord $platformAdsTxtRecord, PlatformAdsTxtService $service): RedirectResponse
    {
        $service->update($platformAdsTxtRecord, $this->validated($request), $request->user());
        return back()->with('status', 'Platform master authorization updated, disabled, and returned to review.');
    }

    public function review(Request $request, PlatformAdsTxtRecord $platformAdsTxtRecord, PlatformAdsTxtService $service): RedirectResponse
    {
        $data = $request->validate(['review_status' => ['required', Rule::enum(SupplyChainReviewStatus::class)]]);
        $service->review($platformAdsTxtRecord, SupplyChainReviewStatus::from($data['review_status']), $request->user());
        return back()->with('status', 'Platform master authorization review recorded.');
    }

    public function enable(
        Request $request,
        PlatformAdsTxtRecord $platformAdsTxtRecord,
        PlatformAdsTxtService $service,
        AuditRecorder $audit,
    ): RedirectResponse {
        $impact = $service->impactedSiteCount();
        $data = $this->validatedImpact($request, 'ENABLE', $impact);
        $service->enable($platformAdsTxtRecord, $request->user());
        $this->auditImpact($audit, $request, $platformAdsTxtRecord, 'ENABLE', $impact, $data['reason']);

        return back()->with('status', 'Platform master authorization enabled for '.$impact.' eligible Horus-managed website(s).');
    }

    public function disable(
        Request $request,
        PlatformAdsTxtRecord $platformAdsTxtRecord,
        PlatformAdsTxtService $service,
        AuditRecorder $audit,
    ): RedirectResponse {
        $impact = $service->impactedSiteCount();
        $data = $this->validatedImpact($request, 'DISABLE', $impact);
        $service->disable($platformAdsTxtRecord, $request->user());
        $this->auditImpact($audit, $request, $platformAdsTxtRecord, 'DISABLE', $impact, $data['reason']);

        return back()->with('status', 'Platform master authorization disabled. Canonical composition excludes it from '.$impact.' currently eligible website(s).');
    }

    public function verify(Request $request, PlatformAdsTxtRecord $platformAdsTxtRecord, PlatformAdsTxtService $service): RedirectResponse
    {
        $record = $service->verify($platformAdsTxtRecord, $request->user());
        return back()->with('status', 'Master sellers.json verification: '.$record->remote_verification_status.'.');
    }

    private function validatedImpact(Request $request, string $action, int $impact): array
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'impact_confirmation' => ['required', 'string', 'max:80'],
        ]);
        $expected = $action.' '.$impact.' SITES';
        if (! hash_equals($expected, strtoupper(trim($data['impact_confirmation'])))) {
            throw ValidationException::withMessages([
                'impact_confirmation' => 'Type '.$expected.' to confirm the platform-wide supply-chain impact.',
            ]);
        }

        return $data;
    }

    private function auditImpact(
        AuditRecorder $audit,
        Request $request,
        PlatformAdsTxtRecord $record,
        string $action,
        int $impact,
        string $reason,
    ): void {
        $audit->record(
            'supply_chain.platform_ads_txt.high_impact_confirmed',
            $request->user()->organization_id,
            $request->user(),
            $record,
            newValues: ['action' => $action, 'impact_count' => $impact, 'reason' => $reason],
        );
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'advertising_system_domain' => ['required', 'string', 'max:255', 'not_regex:/[\s,\x00-\x1F\x7F]/u'],
            'publisher_account_id' => ['required', 'string', 'max:255', 'not_regex:/[\s,\x00-\x1F\x7F]/u'],
            'relationship' => ['required', Rule::in(['DIRECT', 'RESELLER'])],
            'certification_authority_id' => ['nullable', 'string', 'max:128'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
