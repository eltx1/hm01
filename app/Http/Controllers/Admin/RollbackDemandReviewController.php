<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\RollbackDemandEvidence;
use Throwable;

final class RollbackDemandReviewController extends Controller
{
    public function __invoke(Site $site, ConfigVersion $configVersion, DemandConfigurationBuilder $builder, RollbackDemandEvidence $evidence)
    {
        abort_unless($configVersion->site_id === $site->id
            && $configVersion->organization_id === $site->organization_id
            && $configVersion->environment->value === 'PRODUCTION', 404);
        $review = null;
        try {
            $payload = $configVersion->payload ?? [];
            // Missing historical evidence is unknown, not an empty valid setup.
            $historical = $payload['directDemand'] ?? $payload['nativeDemand'] ?? null;
            if (is_array($historical) && is_array($historical['placements'] ?? null)) {
                $review = $evidence->compare($historical, $builder->build($site));
            }
        } catch (Throwable) {
            // Do not expose connector recipes, keys, URLs or exception messages.
        }

        return response()->view('admin.sites.rollback-demand-review', compact('site', 'configVersion', 'review'))
            ->header('Cache-Control', 'private, no-store');
    }
}
