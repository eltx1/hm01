<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConfigVersion;
use App\Models\Site;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Sites\ConfigurationHistoryProjection;
use Illuminate\Validation\ValidationException;

final class RollbackPreviewController extends Controller
{
    public function __invoke(Site $site, ConfigVersion $configVersion, SiteConfigPublisher $publisher, ConfigurationHistoryProjection $projection)
    {
        abort_unless($configVersion->site_id === $site->id && $configVersion->organization_id === $site->organization_id
            && $configVersion->environment->value === 'PRODUCTION', 404);
        $preview = null;
        try {
            $preview = $projection->changes($publisher->previewRollback($site, $configVersion), $configVersion->payload);
        } catch (ValidationException) {
            // Fixed message only: no provider recipes or credentials in the view.
        }

        return response()->view('admin.sites.rollback-preview', compact('site', 'configVersion', 'preview'))
            ->header('Cache-Control', 'private, no-store');
    }
}
