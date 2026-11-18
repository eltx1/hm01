<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Sites\PublisherPageChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SiteInstallationCheckController extends Controller
{
    public function __invoke(Request $request, Site $site, PublisherPageChecker $checker, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->isHorusAdministrator() || $request->user()->organization_id === $site->organization_id, 404);
        $data = $request->validate(['page_url' => ['required', 'string', 'url:http,https', 'max:2048']]);
        $primaryAtCheck = $site->primary_domain;
        $keyAtCheck = $site->public_key;
        $checkedPrimary = strtolower(rtrim((string) parse_url($data['page_url'], PHP_URL_HOST), '.')) === strtolower(rtrim($primaryAtCheck, '.'));
        $result = $checker->check($site, $data['page_url']);
        $audit->record('site.installation_check.completed', $site->organization_id, $request->user(), $site,
            newValues: ['code' => $result['code'], 'http_status' => $result['http_status'],
                'primary_domain' => $primaryAtCheck, 'site_key' => $keyAtCheck, 'checked_primary' => $checkedPrimary]);

        // Do not flash the supplied URL, fetched HTML or raw transport errors.
        $route = $request->user()->isHorusAdministrator() ? 'admin.sites.show' : 'publisher.sites.show';

        return redirect()->route($route, $site)->with('installation_check', $result);
    }
}
