<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupplyChainReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformAdsTxtRecord;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\SupplyChain\PlatformAdsTxtFileEditorService;
use App\Services\SupplyChain\PlatformAdsTxtService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class PlatformAdsTxtController extends Controller
{
    public function index(PlatformAdsTxtService $service, AdsTxtComplianceService $compliance, PlatformAdsTxtFileEditorService $editor): View
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
            'masterFile' => old('master_ads_txt', $editor->currentFile()),
            'masterEditorPreview' => session('master_editor_preview'),
        ]);
    }

    public function store(Request $request, PlatformAdsTxtService $service): RedirectResponse
    {
        $record = $service->create($this->validated($request), $request->user());
        return back()->with('status', 'Platform master authorization created disabled and awaiting review: '.$record->raw_record);
    }

    public function import(Request $request, PlatformAdsTxtService $service): RedirectResponse
    {
        $activate = $request->boolean('activate');
        $data = $request->validate([
            'activate' => ['required', 'boolean'],
            'current_password' => [Rule::requiredIf($activate), 'nullable', 'current_password'],
            'reason' => [Rule::requiredIf($activate), 'nullable', 'string', 'min:8', 'max:1000'],
            'confirm_platform_scope' => [Rule::requiredIf($activate), 'nullable', 'accepted'],
        ]);
        $result = $service->bulkImport($this->importContent($request), $request->user(), $activate, $data['reason'] ?? null);
        $summary = $result['created'].' created, '.$result['activated'].' activated, '.$result['skipped'].' existing, '.count($result['invalid']).' invalid.';

        return back()->with('status', 'Master ads.txt import completed: '.$summary)
            ->with('ads_txt_import_report', $this->importReport($result));
    }

    public function previewEditor(Request $request, PlatformAdsTxtFileEditorService $editor): RedirectResponse
    {
        $data = $request->validate([
            'master_ads_txt' => ['nullable', 'string', 'max:2097152'],
        ]);
        $preview = $editor->preview((string) ($data['master_ads_txt'] ?? ''));

        return back()->withInput()->with('master_editor_preview', $preview);
    }

    public function applyEditor(Request $request, PlatformAdsTxtFileEditorService $editor): RedirectResponse
    {
        $data = $request->validate([
            'master_ads_txt' => ['nullable', 'string', 'max:2097152'],
            'current_password' => ['required', 'current_password'],
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
            'confirm_replace' => ['required', 'accepted'],
        ]);
        $result = $editor->replace((string) ($data['master_ads_txt'] ?? ''), $request->user(), $data['reason']);

        return redirect()->route('admin.compliance.ads-txt.master.index')->with(
            'status',
            'Master ads.txt file applied: '.$result['added_count'].' added, '.$result['removed_count'].' removed, '.$result['changed_count'].' changed, '.$result['unchanged_count'].' unchanged.',
        );
    }

    public function downloadEditor(PlatformAdsTxtFileEditorService $editor): Response
    {
        return response($editor->currentFile(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="horus-master-ads.txt"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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

    private function importContent(Request $request): string
    {
        $data = $request->validate([
            'ads_txt_records' => ['nullable', 'string', 'max:2097152', 'required_without:ads_txt_file'],
            'ads_txt_file' => ['nullable', 'file', 'max:2048', 'mimes:txt,csv', 'required_without:ads_txt_records'],
        ]);
        $parts = [];
        if (filled($data['ads_txt_records'] ?? null)) {
            $parts[] = (string) $data['ads_txt_records'];
        }
        if ($request->hasFile('ads_txt_file')) {
            $parts[] = $request->file('ads_txt_file')->getContent();
        }

        return implode("\n", $parts);
    }

    private function importReport(array $result): array
    {
        return array_merge($result, [
            'invalid' => array_slice($result['invalid'], 0, 50),
            'invalid_total' => count($result['invalid']),
        ]);
    }
}
