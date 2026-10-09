<?php

namespace App\Services\Inventory;

use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\GlobalSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Settings\GlobalSettingsService;

final class VideoPlayerGlobalSettingsService
{
    public const KEYS = ['video_player.content_url', 'video_player.inventory_type', 'video_player.autoplay_audio'];

    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly SiteConfigPublisher $publisher,
    ) {}

    public function set(User $actor, string $key, mixed $rawValue, ?string $reason = null): GlobalSetting
    {
        $row = $this->settings->set($actor, $key, $rawValue, $reason);
        $this->publishActiveSites($actor);

        return $row;
    }

    public function reset(User $actor, string $key, ?string $reason = null): void
    {
        $this->settings->reset($actor, $key, $reason);
        $this->publishActiveSites($actor);
    }

    private function publishActiveSites(User $actor): void
    {
        Site::withoutGlobalScopes()
            ->where('status', SiteStatus::Active->value)
            ->orderBy('id')
            ->each(fn (Site $site) => $this->publisher->publishActiveProduction(
                $site,
                $actor,
                StaticDeliveryPriority::Normal,
            ));
    }
}
