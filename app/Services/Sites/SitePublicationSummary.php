<?php

namespace App\Services\Sites;

use App\Enums\ConfigEnvironment;
use App\Models\Site;

/** Read-only presentation of delivery evidence; never used for serving decisions. */
final class SitePublicationSummary
{
    public function forSite(Site $site): array
    {
        $config = $site->siteConfig;
        $versions = $config?->versions?->where('environment', ConfigEnvironment::Production);
        $latest = $versions?->sortByDesc('version')->first();
        $publishedNumber = (int) ($config?->production_version ?? 0);
        $published = $versions?->firstWhere('version', $publishedNumber);

        return $this->describe(
            $latest?->version,
            $latest?->status?->value,
            $latest?->deliveryItem?->status?->value,
            $publishedNumber ?: null,
            $published?->published_at?->toIso8601String(),
        );
    }

    public function describe(?int $latest, ?string $versionStatus, ?string $deliveryStatus, ?int $published, ?string $publishedAt = null): array
    {
        // The production pointer is updated by delivery confirmation, not by save.
        $state = match (true) {
            $latest === null => 'NOT_QUEUED',
            $versionStatus === 'SUPERSEDED', $deliveryStatus === 'SUPERSEDED' => 'SUPERSEDED',
            $versionStatus === 'ROLLED_BACK' => 'ROLLED_BACK',
            $deliveryStatus === 'RETRY_SCHEDULED' => 'RETRY_SCHEDULED',
            in_array($deliveryStatus, ['BATCHING', 'UPLOADING'], true) => 'PUBLISHING',
            $deliveryStatus === 'PENDING' => 'PENDING',
            $versionStatus === 'DELIVERY_FAILED', $deliveryStatus === 'FAILED' => 'FAILED',
            $latest === $published && in_array($versionStatus, ['DEPLOYED', 'PUBLISHED'], true)
                && ($deliveryStatus === 'DEPLOYED' || ($versionStatus === 'PUBLISHED' && $deliveryStatus === null)) => 'PUBLISHED',
            default => 'PENDING',
        };
        [$label, $detail] = match ($state) {
            'NOT_QUEUED' => ['No production version queued', 'Saved settings alone do not confirm publication. Inactive sites may have no production version.'],
            'FAILED' => ['Publication failed', 'Inspect delivery details in Operations before retrying.'],
            'RETRY_SCHEDULED' => ['Publication retry scheduled', 'The latest version is waiting for another delivery attempt.'],
            'SUPERSEDED' => ['Version superseded', 'This version is no longer the delivery target. Review the current production version.'],
            'ROLLED_BACK' => ['Version rolled back', 'Review the confirmed production version before making another change.'],
            'PUBLISHED' => ['Publication confirmed', 'The delivery system confirmed this production version. This does not verify every visitor cache or an ad impression.'],
            'PUBLISHING' => ['Publication in progress', 'The latest version is being prepared or uploaded.'],
            default => ['Publication pending', 'The latest version has not been confirmed as the production version.'],
        };

        return ['state' => $state, 'label' => $label, 'detail' => $detail,
            'latest_version' => $latest, 'published_version' => $published, 'published_at' => $publishedAt];
    }
}
