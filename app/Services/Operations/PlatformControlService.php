<?php

namespace App\Services\Operations;

use App\Enums\ConfigEnvironment;
use App\Enums\PlacementStatus;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PlatformSetting;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class PlatformControlService
{
    public const DEFAULTS = ['maintenance_mode' => false, 'platform_enabled' => true, 'ads_enabled' => true, 'gam_enabled' => true, 'prebid_enabled' => true, 'native_enabled' => true];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function all(): array
    {
        $stored = PlatformSetting::query()->whereIn('key', array_keys(self::DEFAULTS))->get()->keyBy('key');
        return collect(self::DEFAULTS)->mapWithKeys(fn (bool $default, string $key) => [$key => (bool) data_get($stored->get($key)?->value, 'enabled', $default)])->all();
    }

    public function enabled(string $key): bool
    {
        if (! array_key_exists($key, self::DEFAULTS)) throw new RuntimeException('Unknown platform control.');
        return Cache::remember('platform-control:'.$key, 30, fn () => (bool) data_get(PlatformSetting::query()->find($key)?->value, 'enabled', self::DEFAULTS[$key]));
    }

    public function set(string $key, bool $enabled, User $actor, ?string $reason = null): PlatformSetting
    {
        if (! array_key_exists($key, self::DEFAULTS)) throw new RuntimeException('Unknown platform control.');
        $before = $this->enabled($key);
        $setting = PlatformSetting::query()->updateOrCreate(['key' => $key], [
            'value' => ['enabled' => $enabled, 'reason' => $reason, 'changed_at' => now()->utc()->toIso8601String()],
            'updated_by' => $actor->id,
        ]);
        Cache::forget('platform-control:'.$key);
        if (in_array($key, ['ads_enabled', 'gam_enabled', 'prebid_enabled', 'native_enabled'], true)) $this->publishBrowserControl();
        $this->audit->record('operations.control.updated', $actor->organization_id, $actor, $setting, oldValues: ['enabled' => $before], newValues: ['key' => $key, 'enabled' => $enabled, 'reason' => $reason]);
        return $setting;
    }

    public function publishBrowserControl(): string
    {
        $controls = $this->all();
        $payload = [
            'schemaVersion' => 1,
            'adsEnabled' => $controls['ads_enabled'],
            'gamEnabled' => $controls['gam_enabled'],
            'prebidEnabled' => $controls['prebid_enabled'],
            'nativeEnabled' => $controls['native_enabled'],
            'generatedAt' => now()->utc()->toIso8601String(),
        ];
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $payload['checksum'] = hash('sha256', $encoded);
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $root = rtrim((string) config('horus.static_config_root'), DIRECTORY_SEPARATOR);
        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) throw new RuntimeException('Unable to create CDN config directory.');
        $target = $root.DIRECTORY_SEPARATOR.'control.json';
        $temporary = $target.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to atomically publish browser controls.');
        }
        return $target;
    }

    public function setSite(Site $site, bool $enabled, User $actor, SiteConfigPublisher $publisher, string $reason): void
    {
        $enabled ? $publisher->resume($site, $actor) : $publisher->pauseImmediately($site, $actor);
        $this->audit->record('operations.site.kill_switch', $site->organization_id, $actor, $site, newValues: ['enabled' => $enabled, 'reason' => $reason]);
    }

    public function setPlacement(Placement $placement, bool $enabled, User $actor, SiteConfigPublisher $publisher, string $reason): void
    {
        $before = $placement->status?->value;
        $placement->update(['status' => $enabled ? PlacementStatus::Active : PlacementStatus::Paused, 'updated_by' => $actor->id]);
        $publisher->publish(Site::withoutGlobalScopes()->findOrFail($placement->site_id), ConfigEnvironment::Production, $actor);
        $this->audit->record('operations.placement.kill_switch', $placement->organization_id, $actor, $placement, oldValues: ['status' => $before], newValues: ['status' => $placement->fresh()->status->value, 'reason' => $reason]);
    }

    public function setGamConnection(GamConnection $connection, bool $enabled, User $actor, string $reason): void
    {
        $before = $connection->is_enabled;
        $connection->update(['is_enabled' => $enabled, 'updated_by' => $actor->id]);
        $this->audit->record('operations.gam_connection.kill_switch', $connection->organization_id, $actor, $connection, oldValues: ['is_enabled' => $before], newValues: ['is_enabled' => $enabled, 'reason' => $reason]);
    }
}
