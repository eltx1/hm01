<?php

namespace App\Services\Inventory;

use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\GlobalSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Settings\GlobalSettingsService;

final class VideoGlobalSettingsService
{
    public const KEY = 'video.content_url';

    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly SiteConfigPublisher $publisher,
    ) {}

    public function set(User $actor, mixed $rawValue, ?string $reason = null): GlobalSetting
    {
        $row = $this->settings->set($actor, self::KEY, $rawValue, $reason);
        $this->publishActiveSites($actor);

        return $row;
    }

    public function reset(User $actor, ?string $reason = null): void
    {
        $this->settings->reset($actor, self::KEY, $reason);
        $this->publishActiveSites($actor);
    }

    private function publishActiveSites(User $actor): int
    {
        $published = 0;
        Site::withoutGlobalScopes()
            ->where('status', SiteStatus::Active->value)
            ->orderBy('id')
            ->each(function (Site $site) use ($actor, &$published): void {
                if ($this->publisher->publishActiveProduction($site, $actor, StaticDeliveryPriority::Normal)) {
                    $published++;
                }
            });

        return $published;
    }
}
