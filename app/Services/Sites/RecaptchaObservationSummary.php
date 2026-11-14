<?php

namespace App\Services\Sites;

use App\Enums\ConfigEnvironment;
use App\Models\Site;

final class RecaptchaObservationSummary
{
    public function forSite(Site $site): array
    {
        $settings = $site->servingSettings;
        $number = (int) ($site->siteConfig?->production_version ?? 0);
        $version = $site->siteConfig?->versions?->where('environment', ConfigEnvironment::Production)->firstWhere('version', $number);
        $confirmed = (new SitePublicationSummary)->describe($version?->version, $version?->status?->value,
            $version?->deliveryItem?->status?->value, $number ?: null)['state'] === 'PUBLISHED';

        return $this->describe((bool) $settings?->recaptcha_observation_enabled,
            $settings?->recaptcha_observation_site_key, $confirmed, $version?->payload['recaptchaObservation'] ?? null);
    }

    public function describe(bool $enabled, ?string $siteKey, bool $confirmed, ?array $publicObservation): array
    {
        $publishedEnabled = $confirmed && is_bool($publicObservation['enabled'] ?? null) ? $publicObservation['enabled'] : null;
        $keyMatches = $publishedEnabled === true && is_string($publicObservation['siteKey'] ?? null)
            ? $enabled && $siteKey !== null && $siteKey === $publicObservation['siteKey'] : null;
        $state = match (true) {
            ! $enabled && $publishedEnabled === true => 'DISABLE_PENDING',
            ! $enabled => 'DISABLED',
            ! $confirmed || $publishedEnabled === null => 'NO_PUBLICATION_EVIDENCE',
            $publishedEnabled !== true || $keyMatches !== true => 'UPDATE_PENDING',
            default => 'CONFIGURATION_PUBLISHED',
        };
        $label = match ($state) {
            'DISABLE_PENDING' => 'Disabled in saved settings; published configuration still enables observation',
            'DISABLED' => 'Observation disabled in saved settings',
            'NO_PUBLICATION_EVIDENCE' => 'Enabled in saved settings; publication not confirmed',
            'UPDATE_PENDING' => 'Saved settings differ from confirmed production configuration',
            default => 'Observation configuration published',
        };

        return ['state' => $state, 'label' => $label, 'saved_enabled' => $enabled,
            'site_key_present' => $siteKey !== null && trim($siteKey) !== '', 'published_enabled' => $publishedEnabled,
            'published_key_matches' => $keyMatches];
    }
}
