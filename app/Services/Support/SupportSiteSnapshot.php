<?php

namespace App\Services\Support;

use App\Enums\ConfigEnvironment;
use App\Models\Site;
use App\Services\Sites\SiteHealthEvidence;
use App\Services\Sites\SitePublicationSummary;

/** Minimal historical context; no tokens, raw payloads, keys or provider errors. */
final class SupportSiteSnapshot
{
    public function capture(Site $site): array
    {
        $site->loadMissing(['siteConfig', 'monetizationHealthStates:id,site_id,status,observed_at']);
        $config = $site->siteConfig;
        if ($config) {
            $latest = $site->configVersions()->where('environment', ConfigEnvironment::Production->value)->latest('version')->first();
            $confirmed = $latest && (int) $config->production_version === $latest->version ? $latest
                : $site->configVersions()->where('environment', ConfigEnvironment::Production->value)
                    ->where('version', (int) $config->production_version)->first();
            $versions = collect([$latest, $confirmed])->filter()->unique('id')->values();
            $versions->each(fn ($version) => $version->loadMissing('deliveryItem'));
            $config->setRelation('versions', $versions);
        }

        return ['schema' => 1, 'captured_at_utc' => now()->utc()->toIso8601String(),
            'publication' => (new SitePublicationSummary)->forSite($site),
            'monitor' => (new SiteHealthEvidence)->forSite($site)];
    }
}
