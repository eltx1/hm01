<?php

namespace App\Http\Controllers\Publisher;

use App\Enums\VerificationMethod;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Audit\AuditRecorder;
use App\Services\Sites\DomainVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SiteVerificationController extends Controller
{
    public function verify(Request $request, Site $site, SiteDomain $domain, DomainVerificationService $service, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($domain->site_id === $site->id, 404);
        $data = $request->validate(['method' => ['required', 'in:META_TAG,TEXT_FILE,DNS_TXT']]);
        $verification = $service->verify($domain, VerificationMethod::from($data['method']), $request->user());
        $audit->record('site.domain.verification_attempted', $site->organization_id, $request->user(), $domain, newValues: ['method' => $data['method'], 'status' => $verification->status]);

        return back()->with($verification->status === 'VERIFIED' ? 'status' : 'error', $verification->status === 'VERIFIED' ? 'Domain verified.' : $verification->failure_reason);
    }
}
