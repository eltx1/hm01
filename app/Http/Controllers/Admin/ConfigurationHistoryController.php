<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Sites\ConfigurationHistoryProjection;
use Illuminate\Http\Request;

final class ConfigurationHistoryController extends Controller
{
    public function __invoke(Request $request, Site $site, ConfigurationHistoryProjection $projection)
    {
        $query = $site->configVersions()->where('organization_id', $site->organization_id)->where('environment', ConfigEnvironment::Production);
        $number = (int) ($site->siteConfig?->production_version ?? 0);
        $confirmed = $number > 0 ? (clone $query)->where('version', $number)->first() : null;
        $versions = $query->with('deliveryItem')->orderByDesc('version')->paginate(20);
        $rows = $versions->getCollection()->map(fn ($version) => [
            'version' => $version->version, 'status' => $version->status->value,
            'delivery' => $version->deliveryItem?->status?->value ?? 'UNKNOWN',
            'created_at' => $version->created_at?->utc()->toIso8601String(),
            'confirmed' => $confirmed?->id === $version->id,
            'comparison' => $projection->changes($version->payload ?? [], $confirmed?->payload),
        ]);

        return view('admin.sites.configuration-history', compact('site', 'versions', 'rows', 'confirmed'));
    }
}
