<?php

namespace App\Services\Inventory;

use App\Enums\SiteStatus;
use App\Enums\StaticDeliveryPriority;
use App\Models\GlobalSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Settings\GlobalSettingsService;

final class ClickGuardGlobalSettingsService
{
    public const KEYS = [
        'click_guard.enabled',
        'click_guard.max_clicks',
        'click_guard.window_hours',
        'click_guard.block_hours',
    ];

    public function __construct(
        private readonly GlobalSettingsService $settings,
        private readonly SiteConfigPublisher $publisher,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $values */
    public function update(User $actor, array $values, string $reason): int
    {
        $before = collect(self::KEYS)->mapWithKeys(fn (string $key): array => [$key => $this->settings->get($key)])->all();

        foreach (self::KEYS as $key) {
            $this->settings->set($actor, $key, $values[$key], $reason);
        }

        $after = collect(self::KEYS)->mapWithKeys(fn (string $key): array => [$key => $this->settings->get($key)])->all();
        if ($before === $after) {
            return 0;
        }

        $this->audit->record(
            'click_guard.global_policy.updated',
            null,
            $actor,
            null,
            $before,
            $after,
            ['reason' => mb_substr($reason, 0, 500), 'client_only' => true],
        );

        return $this->publishActiveSites($actor);
    }

    public function set(User $actor, string $key, mixed $rawValue, ?string $reason = null): GlobalSetting
    {
        $before = $this->settings->get($key);
        $row = $this->settings->set($actor, $key, $rawValue, $reason);
        $after = $this->settings->get($key);
        if ($before !== $after) {
            $this->publishActiveSites($actor);
        }

        return $row;
    }

    public function reset(User $actor, string $key, ?string $reason = null): void
    {
        $before = $this->settings->get($key);
        $this->settings->reset($actor, $key, $reason);
        if ($before !== $this->settings->get($key)) {
            $this->publishActiveSites($actor);
        }
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
