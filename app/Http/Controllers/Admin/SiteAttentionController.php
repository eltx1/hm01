<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\SiteHealthEvidence;
use Illuminate\Http\Request;

final class SiteAttentionController extends Controller
{
    public function __invoke(Request $request, SiteHealthEvidence $evidence)
    {
        $filter = $request->string('evidence')->value();
        if (! in_array($filter, ['findings', 'stale', 'missing'], true)) $filter = 'findings';
        $sites = Site::query()->where('status', 'ACTIVE')->with(['monetizationHealthStates' => fn ($query) => $query->select('id', 'site_id', 'state_key', 'status', 'observed_at')])
            ->when($filter === 'findings', fn ($q) => $q->whereHas('monetizationHealthStates', fn ($s) => $s->where('status', 'BROKEN')))
            ->when($filter === 'missing', fn ($q) => $q->whereDoesntHave('monetizationHealthStates'))
            ->when($filter === 'stale', fn ($q) => $q->whereHas('monetizationHealthStates', fn ($s) => $s->whereNull('observed_at')->orWhere('observed_at', '<', now()->subHour())->orWhere('observed_at', '>', now())))
            ->orderBy('id')->paginate(20)->withQueryString();
        $rows = $sites->getCollection()->map(fn (Site $site) => ['site' => $site, 'summary' => $evidence->forSite($site),
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
