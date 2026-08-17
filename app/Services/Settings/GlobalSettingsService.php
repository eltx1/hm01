<?php

namespace App\Services\Settings;

use App\Models\GlobalSetting;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\TrafficGate\TrafficGateSettingsValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class GlobalSettingsService
{
    public const CACHE_KEY = 'horus.global-settings.overrides.v1';

    /** @var array<string, mixed> */
    private array $fallbacks = [];

    public function __construct(
        private readonly TypedSettingsRegistry $registry,
        private readonly AuditRecorder $audit,
        private readonly TrafficGateSettingsValidator $trafficGateValidator,
    ) {
        foreach ($this->registry->all() as $key => $definition) {
            $this->fallbacks[$key] = config($definition->configPath);
        }
    }

    public function applyRuntimeOverrides(): void
    {
        foreach ($this->overrides() as $key => $value) {
            $definition = $this->registry->all()[$key] ?? null;
            if ($definition?->runtimeEditable) {
                config([$definition->configPath => $value]);
            }
        }
    }

    public function get(string $key): mixed
    {
        $this->registry->get($key);
        $overrides = $this->overrides();

        return array_key_exists($key, $overrides) ? $overrides[$key] : $this->fallbacks[$key];
    }

    /** @return array<int, array<string, mixed>> */
    public function describe(): array
    {
        $rows = $this->storedRows();

        return collect($this->registry->all())->map(function (SettingDefinition $definition) use ($rows): array {
            $row = $rows->get($definition->key);

            return [
                'definition' => $definition,
                'value' => $row ? $row->value : $this->fallbacks[$definition->key],
                'default' => $this->fallbacks[$definition->key],
                'source' => $row ? 'DATABASE_OVERRIDE' : 'CONFIG_FALLBACK',
                'changed_at' => $row?->updated_at,
                'changed_by' => $row?->changedBy?->name,
            ];
        })->values()->all();
    }

    public function set(User $actor, string $key, mixed $rawValue, ?string $reason = null): GlobalSetting
    {
        $definition = $this->registry->get($key);
        $value = $this->registry->normalize($key, $rawValue);
        $before = $this->get($key);
        $this->validateTrafficGateProspectiveValue($key, $value);

        $row = DB::transaction(function () use ($actor, $key, $value): GlobalSetting {
            return GlobalSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'changed_by' => $actor->id],
            );
        });

        $this->invalidate();
        config([$definition->configPath => $value]);
        // GlobalSetting uses a controlled string key, while AuditLog auditable IDs are ULIDs.
        // Keep the setting key in safe structured values/metadata instead of overloading auditable_id.
        $this->audit->record('settings.global.updated', null, $actor, null,
            ['key' => $key, 'value' => $before],
            ['key' => $key, 'value' => $value],
            ['setting_key' => $key, 'group' => $definition->group, 'high_impact' => $definition->highImpact, 'reason' => $reason ? mb_substr($reason, 0, 500) : null],
        );
        $this->auditAdvertiserCampaignFeatureChange($actor, $key, $before, $value, $reason);

        return $row->refresh();
    }

    public function reset(User $actor, string $key, ?string $reason = null): void
    {
        $definition = $this->registry->get($key);
        $before = $this->get($key);
        $fallback = $this->fallbacks[$key];
        $this->validateTrafficGateProspectiveValue($key, $fallback);
        GlobalSetting::query()->whereKey($key)->delete();
        $this->invalidate();
        config([$definition->configPath => $fallback]);
        $this->audit->record('settings.global.reset', null, $actor, null,
            ['key' => $key, 'value' => $before],
            ['key' => $key, 'value' => $fallback],
            ['setting_key' => $key, 'group' => $definition->group, 'reason' => $reason ? mb_substr($reason, 0, 500) : null],
        );
        $this->auditAdvertiserCampaignFeatureChange($actor, $key, $before, $fallback, $reason);
    }

    public function invalidate(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Cache availability must never make Settings or application boot unsafe.
        }
    }

    /** @return array<string, mixed> */
    private function overrides(): array
    {
        try {
            if (! Schema::hasTable('global_settings')) {
                return [];
            }

            return Cache::remember(self::CACHE_KEY, 300, fn (): array => GlobalSetting::query()
                ->get(['key', 'value'])
                ->filter(fn (GlobalSetting $row): bool => array_key_exists($row->key, $this->registry->all()))
                ->mapWithKeys(fn (GlobalSetting $row): array => [$row->key => $row->value])
                ->all());
        } catch (Throwable) {
            try {
                if (! Schema::hasTable('global_settings')) {
                    return [];
                }

                return GlobalSetting::query()->get(['key', 'value'])
                    ->filter(fn (GlobalSetting $row): bool => array_key_exists($row->key, $this->registry->all()))
                    ->mapWithKeys(fn (GlobalSetting $row): array => [$row->key => $row->value])->all();
            } catch (Throwable) {
                return [];
            }
        }
    }

    private function storedRows()
    {
        try {
            if (! Schema::hasTable('global_settings')) {
                return collect();
            }

            return GlobalSetting::query()->with('changedBy')->get()->keyBy('key');
        } catch (Throwable) {
            return collect();
        }
    }

    private function validateTrafficGateProspectiveValue(string $key, mixed $value): void
    {
        if (! in_array($key, TrafficGateSettingsValidator::KEYS, true)) {
            return;
        }

        $prospective = [];
        foreach (TrafficGateSettingsValidator::KEYS as $trafficGateKey) {
            $prospective[$trafficGateKey] = $trafficGateKey === $key ? $value : $this->get($trafficGateKey);
        }

        $this->trafficGateValidator->validate($prospective);
    }

    private function auditAdvertiserCampaignFeatureChange(User $actor, string $key, mixed $before, mixed $after, ?string $reason): void
    {
        if ($key !== 'advertiser_campaigns.enabled' || $before === $after) {
            return;
        }

        $this->audit->record(
            'advertiser_campaigns.enabled_changed',
            null,
            $actor,
            null,
            ['enabled' => (bool) $before],
            ['enabled' => (bool) $after],
            ['setting_key' => $key, 'reason' => $reason ? mb_substr($reason, 0, 500) : null],
        );
    }
}
