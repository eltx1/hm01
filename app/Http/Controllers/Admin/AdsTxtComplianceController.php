<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DemandAccount;
use App\Models\DemandAdsTxtRecord;
use App\Models\Site;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Compliance\AdsTxtRecordManager;
use App\Services\Compliance\AdsTxtVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class AdsTxtComplianceController extends Controller
{
    public function index(Request $request, AdsTxtComplianceService $compliance): View
    {
        $sites = Site::withoutGlobalScopes()->with(['publisher', 'domains'])->orderBy('primary_domain')->paginate(25);
        $summaries = $sites->getCollection()->mapWithKeys(fn (Site $site): array => [$site->id => $compliance->summary($site)]);

        return view('admin.compliance.ads-txt.index', [
            'sites' => $sites,
            'summaries' => $summaries,
            'accounts' => DemandAccount::withoutGlobalScopes()->with('network')->where('is_enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function show(Site $site, AdsTxtComplianceService $compliance): View
    {
        $site->load(['publisher', 'domains']);
        $accountIds = $site->demandSites()->pluck('demand_account_id');
        $records = DemandAdsTxtRecord::withoutGlobalScopes()->with(['account.network', 'site'])
            ->whereIn('demand_account_id', $accountIds)
            ->where(fn ($query) => $query->where('site_id', $site->id)->orWhereNull('site_id'))
            ->orderBy('raw_record')->get();
        $auditableIds = $records->pluck('id')->push($site->id);

        return view('admin.compliance.ads-txt.show', [
            'site' => $site,
            'summary' => $compliance->summary($site),
            'history' => $compliance->history($site),
            'records' => $records,
            'accounts' => DemandAccount::withoutGlobalScopes()->with('network')->whereIn('id', $accountIds)->orderBy('name')->get(),
            'auditEvents' => AuditLog::query()->where('event', 'like', 'supply_chain.ads_txt.%')
                ->where(fn ($query) => $query->whereIn('auditable_id', $auditableIds)
                    ->orWhereJsonContains('new_values->site_ids', $site->id))
                ->latest()->limit(50)->get(),
        ]);
    }

    public function download(Site $site, AdsTxtComplianceService $compliance): Response
    {
        $content = $compliance->canonical($site)['content'];
        $name = preg_replace('/[^a-z0-9.-]/', '-', strtolower($site->primary_domain)).'-ads.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(Request $request, Site $site, AdsTxtVerifier $verifier): RedirectResponse
    {
        $check = $verifier->verify($site, 'ADMIN', $request->user());

        return back()->with('status', 'Ads.txt verification completed: '.$check->status.'.');
    }

    public function storeRecord(Request $request, AdsTxtRecordManager $manager): RedirectResponse
    {
        $record = $manager->create($this->recordData($request), $request->user());

        return back()->with('status', 'Managed ads.txt record created: '.$record->raw_record);
    }

    public function updateRecord(Request $request, DemandAdsTxtRecord $record, AdsTxtRecordManager $manager): RedirectResponse
    {
        $manager->update($record, $this->recordData($request), $request->user());

        return back()->with('status', 'Managed ads.txt record updated.');
    }

    public function disableRecord(Request $request, DemandAdsTxtRecord $record, AdsTxtRecordManager $manager): RedirectResponse
    {
        $manager->disable($record, $request->user());

        return back()->with('status', 'Managed ads.txt record disabled.');
    }

    public function bulkAssign(Request $request, AdsTxtRecordManager $manager): RedirectResponse
    {
        $data = $this->recordData($request, true);
        $siteIds = $request->validate(['site_ids' => ['required', 'array', 'min:1', 'max:100'], 'site_ids.*' => ['required', 'ulid', 'distinct', 'exists:sites,id']])['site_ids'];
        $result = $manager->bulkAssign($data, $siteIds, $request->user());

        return back()->with('status', $result['created'].' managed record(s) assigned; '.$result['skipped'].' existing record(s) skipped.');
    }

    public function bulkImport(Request $request, AdsTxtRecordManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'demand_account_id' => ['required', 'ulid', 'exists:demand_accounts,id'],
            'site_id' => ['nullable', 'ulid', 'exists:sites,id'],
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
        $result = $manager->bulkImport(implode("\n", $parts), $data['demand_account_id'], $data['site_id'] ?? null, $request->user());
        $summary = $result['created'].' created, '.$result['skipped'].' existing, '.count($result['invalid']).' invalid.';

        return back()->with('status', 'Demand ads.txt import completed: '.$summary)
            ->with('ads_txt_import_report', array_merge($result, [
                'invalid' => array_slice($result['invalid'], 0, 50),
                'invalid_total' => count($result['invalid']),
            ]));
    }

    /** @return array<string, mixed> */
    private function recordData(Request $request, bool $bulk = false): array
    {
        return $request->validate([
            'demand_account_id' => ['required', 'ulid', 'exists:demand_accounts,id'],
            'site_id' => ['nullable', 'ulid', 'exists:sites,id'],
            'domain' => ['required', 'string', 'max:253'],
            'publisher_account_id' => ['required', 'string', 'max:255'],
            'relationship' => ['required', Rule::in(['DIRECT', 'RESELLER'])],
            'certification_authority_id' => ['nullable', 'string', 'max:128'],
        ]);
    }
}
