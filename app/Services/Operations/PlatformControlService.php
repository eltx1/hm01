<?php

namespace App\Services\Operations;

use App\Models\PlatformControl;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class PlatformControlService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function disabled(string $scopeType, ?string $scopeId, string $control): bool
    {
        $scopeId = $scopeType === 'PLATFORM' ? 'GLOBAL' : $scopeId;
        $key = $this->cacheKey($scopeType, $scopeId, $control);
        return Cache::remember($key, now()->addSeconds(30), fn () => (bool) PlatformControl::query()
            ->where('scope_type', $scopeType)->where('scope_id', $scopeId)
            ->where('control_key', $control)->value('is_disabled'));
    }

    public function disabledForSite(string $control, string $siteId, ?string $connectionId = null): bool
    {
        return $this->disabled('PLATFORM', null, $control)
            || $this->disabled('SITE', $siteId, $control)
            || ($connectionId && $control === 'AD_SERVING' && $this->disabled('GAM_CONNECTION', $connectionId, 'AD_SERVING'));
    }

    public function set(string $scopeType, ?string $scopeId, string $control, bool $disabled, string $reason, User $actor): PlatformControl
    {
        $allowed = (array) config('operations.controls.'.$scopeType, []);
        if (! in_array($control, $allowed, true)) {
            throw ValidationException::withMessages(['control_key' => 'The requested operational control is not supported for this scope.']);
        }
        if ($scopeType === 'PLATFORM') $scopeId = 'GLOBAL';
        if ($scopeType !== 'PLATFORM' && ! $scopeId) {
            throw ValidationException::withMessages(['scope_id' => 'A scope identifier is required.']);
        }

        $record = PlatformControl::query()->updateOrCreate(
            ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'control_key' => $control],
            ['is_disabled' => $disabled, 'reason' => $reason, 'changed_by' => $actor->id, 'changed_at' => now()],
        );
        Cache::forget($this->cacheKey($scopeType, $scopeId, $control));
        if ($scopeType === 'PLATFORM') {
            $this->publishGlobalSnapshot();
        }
        $this->audit->record('operations.control.changed', $actor->organization_id, $actor, $record, newValues: [
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'control_key' => $control,
            'is_disabled' => $disabled, 'reason' => $reason,
        ]);
        return $record->refresh();
    }

    public function placementDisabled(string $placementId): bool
    {
        return $this->disabled('PLATFORM', null, 'AD_SERVING') || $this->disabled('PLACEMENT', $placementId, 'AD_SERVING');
    }

    private function publishGlobalSnapshot(): void
    {
        $records = PlatformControl::query()->where('scope_type', 'PLATFORM')->get()->keyBy('control_key');
        $payload = [
            'version' => now()->getTimestampMs(),
            'generatedAt' => now()->utc()->toIso8601String(),
            'controls' => [
                'adServingDisabled' => (bool) $records->get('AD_SERVING')?->is_disabled,
                'prebidDisabled' => (bool) $records->get('PREBID')?->is_disabled,
                'nativeDemandDisabled' => (bool) $records->get('NATIVE_DEMAND')?->is_disabled,
            ],
        ];
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $root = rtrim((string) config('horus.static_config_root'), DIRECTORY_SEPARATOR);
        $directory = $root.DIRECTORY_SEPARATOR.'_global';
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Unable to create the global CDN control directory.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'control.json';
        $temporary = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $encoded, LOCK_EX) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to atomically publish the global CDN control file.');
        }
    }

    private function cacheKey(string $scopeType, ?string $scopeId, string $control): string
    {
        return 'operations:control:'.strtolower($scopeType).':'.($scopeId ?: 'GLOBAL').':'.strtolower($control);
    }
}
