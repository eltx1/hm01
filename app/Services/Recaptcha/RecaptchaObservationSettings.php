<?php

namespace App\Services\Recaptcha;

use App\Enums\StaticDeliveryPriority;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Support\Facades\DB;

final class RecaptchaObservationSettings
{
    public function __construct(private readonly AuditRecorder $audit, private readonly SiteConfigPublisher $publisher) {}

    public function update(Site $site, User $actor, array $data): void
    {
        DB::transaction(function () use ($site, $actor, $data): bool {
            $settings = $site->servingSettings()->lockForUpdate()->firstOrFail();
            $before = $this->summary($settings);
            $settings->recaptcha_observation_enabled = (bool) $data['enabled'];
            $settings->recaptcha_observation_site_key = $data['site_key'] ?? null;
            $secretChanged = false;
            if ($data['clear_secret'] ?? false) {
                $secretChanged = $settings->getRawOriginal('recaptcha_observation_secret') !== null;
                $settings->recaptcha_observation_secret = null;
            } elseif (! empty($data['recaptcha_observation_secret'])) {
                $secretChanged = $settings->recaptcha_observation_secret !== $data['recaptcha_observation_secret'];
                if ($secretChanged) $settings->recaptcha_observation_secret = $data['recaptcha_observation_secret'];
            }
            $after = $this->summary($settings);
            if ($before === $after && ! $secretChanged) return false;
            $settings->configuration_version = (int) $settings->configuration_version + 1;
            $settings->save();
            $this->audit->record('recaptcha.observation_settings_changed', $site->organization_id, $actor, $site,
                $before, $after, ['secret_changed' => $secretChanged, 'mode' => 'OBSERVATION_ONLY']);
            $this->publisher->publishActiveProduction($site->refresh(), $actor, StaticDeliveryPriority::Normal);
            return true;
        });
    }

    private function summary($settings): array
    {
        return [
            'enabled' => (bool) $settings->recaptcha_observation_enabled,
            'site_key' => $settings->recaptcha_observation_site_key,
        ];
    }
}
