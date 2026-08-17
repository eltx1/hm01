<?php

namespace App\Services\Operations;

use App\Models\DemandNetwork;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PlatformControl;
use App\Models\Site;
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
            ->where('scope_type', $scopeType)
            ->where('scope_id', $scopeId)
            ->where('control_key', $control)
            ->value('is_disabled'));
    }

    public function disabledForSite(string $control, string $siteId, ?string $connectionId = null): bool
    {
        return $this->disabled('PLATFORM', null, $control)
            || $this->disabled('SITE', $siteId, $control)
            || ($connectionId
                && in_array($control, ['AD_SERVING', 'GAM'], true)
                && $this->disabled('GAM_CONNECTION', $connectionId, $control));
    }

    public function set(string $scopeType, ?string $scopeId, string $control, bool $disabled, string $reason, User $actor): PlatformControl
    {
        $scopeType = strtoupper($scopeType);
        $control = strtoupper($control);
        $allowed = (array) config('operations.controls.'.$scopeType, []);
        if (! in_array($control, $allowed, true)) {
            throw ValidationException::withMessages(['control_key' => 'The requested operational control is not supported for this scope.']);
        }

        if ($scopeType === 'PLATFORM') {
            $scopeId = 'GLOBAL';
        } elseif (! $scopeId) {
            throw ValidationException::withMessages(['scope_id' => 'A scope identifier is required.']);
        } else {
            $this->assertTargetExists($scopeType, $scopeId);
        }

        $record = PlatformControl::query()->firstOrNew([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'control_key' => $control,
        ]);

        // Replay-safe: a repeated request for the current state performs no write,
        // no cache invalidation and no duplicate static publication.
        if ($record->exists && (bool) $record->is_disabled === $disabled) {
            return $record;
        }

        $oldValues = $record->exists ? [
            'is_disabled' => (bool) $record->is_disabled,
            'reason' => $record->reason,
            'changed_by' => $record->changed_by,
            'changed_at' => $record->changed_at?->toIso8601String(),
        ] : [];

        $record->fill([
            'is_disabled' => $disabled,
            'reason' => $reason,
            'changed_by' => $actor->id,
            'changed_at' => now(),
        ]);
        $record->save();

        Cache::forget($this->cacheKey($scopeType, $scopeId, $control));
        $this->audit->record(
            'operations.control.changed',
            $actor->organization_id,
            $actor,
            $record,
            oldValues: $oldValues,
            newValues: [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'control_key' => $control,
                'is_disabled' => $disabled,
                'reason' => $reason,
            ],
        );

        if ($scopeType === 'PLATFORM' && $control === 'TRAFFIC_GATE') {
            $this->audit->record(
                $disabled ? 'traffic_gate.emergency_disabled' : 'traffic_gate.emergency_disable_cleared',
                $actor->organization_id,
                $actor,
                $record,
                ['disabled' => (bool) ($oldValues['is_disabled'] ?? false)],
                ['disabled' => $disabled],
                ['reason' => mb_substr($reason, 0, 2000), 'client_only' => true],
            );
        }

        return $record;
    }

    public function placementDisabled(string $placementId): bool
    {
        return $this->disabled('PLATFORM', null, 'AD_SERVING')
            || $this->disabled('PLACEMENT', $placementId, 'AD_SERVING');
    }

    public function placementEngineDisabled(string $placementId, string $control): bool
    {
        return $this->disabled('PLATFORM', null, 'AD_SERVING')
            || $this->disabled('PLACEMENT', $placementId, 'AD_SERVING')
            || $this->disabled('PLATFORM', null, $control)
            || $this->disabled('PLACEMENT', $placementId, $control);
    }

    private function assertTargetExists(string $scopeType, string $scopeId): void
    {
        $exists = match ($scopeType) {
            'SITE' => Site::withoutGlobalScopes()->whereKey($scopeId)->exists(),
            'PLACEMENT' => Placement::withoutGlobalScopes()->whereKey($scopeId)->exists(),
            'GAM_CONNECTION' => GamConnection::withoutGlobalScopes()->whereKey($scopeId)->exists(),
            'DEMAND_NETWORK' => DemandNetwork::withoutGlobalScopes()->whereKey($scopeId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw ValidationException::withMessages(['scope_id' => 'The selected operational-control target does not exist.']);
        }
    }

    private function cacheKey(string $scopeType, ?string $scopeId, string $control): string
    {
        return 'operations:control:'.strtolower($scopeType).':'.($scopeId ?: 'GLOBAL').':'.strtolower($control);
    }
}
