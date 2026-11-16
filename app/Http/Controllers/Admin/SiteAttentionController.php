<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteHealthEvidence;
use App\Services\Sites\PublicationAttention;
use App\Services\Sites\SitePublicationSummary;
use App\Services\Sites\StoredSourceAttention;
use Illuminate\Http\Request;

final class SiteAttentionController extends Controller
{
    public function __invoke(Request $request, SiteHealthEvidence $evidence)
    {
        $filter = $request->string('evidence')->value();
        if (! in_array($filter, ['findings', 'stale', 'missing', 'publication', 'ads_txt', 'synthetic'], true)) $filter = 'findings';
        $sites = Site::query()->where('status', 'ACTIVE')->with(['monetizationHealthStates' => fn ($query) => $query->select('id', 'site_id', 'state_key', 'status', 'observed_at')])
            ->when($filter === 'findings', fn ($q) => $q->whereHas('monetizationHealthStates', fn ($s) => $s->where('status', 'BROKEN')))
            ->when($filter === 'missing', fn ($q) => $q->whereDoesntHave('monetizationHealthStates'))
            ->when($filter === 'stale', fn ($q) => $q->whereHas('monetizationHealthStates', fn ($s) => $s->whereNull('observed_at')->orWhere('observed_at', '<', now()->subHour())->orWhere('observed_at', '>', now())))
            ->when($filter === 'publication', fn ($q) => $q->whereHas('configVersions', fn ($versions) => PublicationAttention::issues($versions)))
            ->when($filter === 'ads_txt', fn ($q) => $q->whereHas('verifications', fn ($v) => StoredSourceAttention::adsTxt($v)->where('status', 'FAILED')))
            ->when($filter === 'synthetic', fn ($q) => $q->whereHas('syntheticProbeResults', fn ($v) => StoredSourceAttention::probes($v)->whereIn('status', ['FAIL', 'FAILED', 'ERROR'])))
            ->with(['siteConfig:id,site_id,organization_id,production_version', 'configVersions' => fn ($versions) => PublicationAttention::latest($versions->getQuery())
                ->select('id', 'site_id', 'site_config_id', 'organization_id', 'version', 'status', 'environment')
                ->with(['deliveryItem:id,config_version_id,status'])])
            ->with(['verifications' => fn ($v) => StoredSourceAttention::adsTxt($v->getQuery())->select('id', 'site_id', 'site_domain_id', 'organization_id', 'status', 'attempted_at'),
                'syntheticProbeResults' => fn ($v) => StoredSourceAttention::probes($v->limit(10)->getQuery())->select('id', 'site_id', 'organization_id', 'status', 'observed_at')])
            ->orderBy('id')->paginate(20)->withQueryString();
        $rows = $sites->getCollection()->map(fn (Site $site) => ['site' => $site, 'summary' => $evidence->forSite($site),
            'publication' => (new SitePublicationSummary)->describe(
                $site->configVersions->first()?->version, $site->configVersions->first()?->status?->value,
                $site->configVersions->first()?->deliveryItem?->status?->value,
                $site->siteConfig?->production_version ?: null),
            'sources' => $site->verifications->map(fn ($v) => ['label' => 'Primary-domain ads.txt attempt', 'status' => in_array($v->status, ['VERIFIED', 'FAILED', 'PENDING'], true) ? $v->status : 'UNKNOWN', 'time' => $v->attempted_at?->utc()->toIso8601String()])
                ->concat($site->syntheticProbeResults->map(fn ($v) => ['label' => 'Production synthetic probe', 'status' => in_array($v->status, ['PASS', 'FAIL', 'FAILED', 'ERROR'], true) ? $v->status : 'UNKNOWN', 'time' => $v->observed_at?->utc()->toIso8601String()])),
            'checks' => $site->monetizationHealthStates->map(fn ($state) => [
                'label' => match (true) {
                    $state->state_key === 'no_active_engine' => 'All monetization engines',
                    $state->state_key === 'renderer_conflict' => 'Renderer ownership',
                    $state->state_key === 'provider_account_suspended' => 'Provider account',
                    $state->state_key === 'prebid_configuration' => 'Prebid configuration',
                    $state->state_key === 'unsafe_tag_recipe' => 'Provider tag safety',
                    str_starts_with($state->state_key, 'report:') => 'Reporting source',
                    default => 'Stored monetization check',
                },
                'status' => in_array($state->status, ['BROKEN', 'HEALTHY'], true) ? $state->status : 'UNKNOWN',
                'observed_at' => $state->observed_at?->utc()->toIso8601String(),
            ]),
        ]);

        return view('admin.operations.site-attention', compact('sites', 'rows', 'filter'));
    }
}
