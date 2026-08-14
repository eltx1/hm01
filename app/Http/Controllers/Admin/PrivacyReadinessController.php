<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Privacy\PrivacyReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class PrivacyReadinessController extends Controller
{
    public function run(Request $request, Site $site, PrivacyReadinessService $privacy): View
    {
        $data = $request->validate(['environment' => ['required', Rule::enum(ConfigEnvironment::class)]]);
        $diagnostic = $privacy->issueDiagnostic($site, ConfigEnvironment::from($data['environment']), $request->user());

        return view('admin.privacy-diagnostics.created', compact('site', 'diagnostic'));
    }

    public function googleCmp(Request $request, Site $site, PrivacyReadinessService $privacy): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::enum(ConfigEnvironment::class)],
            'cmp_name' => ['required', 'string', 'max:255'],
            'tcf_cmp_id' => ['required', 'integer', 'between:0,4294967295'],
            'platform' => ['required', Rule::in(['WEB', 'APP', 'CTV', 'WEB_AND_APP', 'WEB_APP_CTV'])],
            'last_verification_date' => ['required', 'date', 'before_or_equal:today'],
            'operator_verification_status' => ['required', Rule::in(['VERIFIED', 'NOT_VERIFIED'])],
        ]);
        $privacy->recordGoogleCmpEvidence($site, ConfigEnvironment::from($data['environment']), $data, $request->user());

        return back()->with('status', 'Google CMP operator evidence recorded. This does not certify legal compliance.');
    }
}
