<?php

namespace App\Services\Inventory;

use App\Models\AdUnit;
use App\Models\GamRemoteObject;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\Data\GamResult;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Validation\ValidationException;

final class AdUnitSyncService
{
    public function __construct(
        private readonly GamConnectionResolver $connections,
        private readonly GamConnectorManager $connectors,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function sync(AdUnit $adUnit, User $actor, bool $dryRun = true, bool $force = false): GamResult
    {
        $adUnit->loadMissing(['site', 'sizes']);
        $connection = $this->connections->requireFor($adUnit->site);
        $attributes = $this->attributes($adUnit, $connection->configuration ?? []);
        $hash = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
        $mapping = $this->mapping($adUnit, $connection->id);

        if ($mapping && $mapping->payload_hash === $hash && ! $force) {
            $adUnit->update(['sync_status' => 'IN_SYNC', 'last_sync_hash' => $hash, 'last_synced_at' => now(), 'updated_by' => $actor->id]);

            return GamResult::duplicate([
                'remoteId' => $mapping->remote_object_id,
                'differenceDetected' => false,
            ]);
        }

        $options = [
            'dry_run' => $dryRun,
            'local_type' => 'ad_unit',
            'local_id' => $adUnit->id,
            'remote_type' => 'ad_unit',
            'remote_id_path' => 'rval.0.id',
            'idempotency_key' => hash('sha256', $connection->id.'|ad_unit|'.$adUnit->id.'|'.$hash.'|'.($mapping ? 'update' : 'create')),
            'mapping_metadata' => ['site_id' => $adUnit->site_id, 'code' => $adUnit->code],
        ];

        $connector = $this->connectors->for($connection);
        if ($mapping) {
            $attributes['id'] = $mapping->remote_object_id;
            $result = $connector->updateAdUnit($attributes, $options);
        } else {
            $result = $connector->createAdUnit($attributes, $options);
        }

        $status = match (true) {
            $result->dryRun => 'DRY_RUN',
            $result->success => 'IN_SYNC',
            default => 'FAILED',
        };
        $adUnit->update([
            'sync_status' => $status,
            'last_sync_hash' => $result->success && ! $result->dryRun ? $hash : $adUnit->last_sync_hash,
            'last_synced_at' => $result->success && ! $result->dryRun ? now() : $adUnit->last_synced_at,
            'updated_by' => $actor->id,
        ]);

        $this->audit->record('inventory.ad_unit.synchronized', $adUnit->organization_id, $actor, $adUnit, newValues: [
            'connection_id' => $connection->id,
            'network_code' => $connection->network_code,
            'dry_run' => $dryRun,
            'force' => $force,
            'operation_id' => $result->operationId,
            'success' => $result->success,
            'difference_detected' => $mapping ? $mapping->payload_hash !== $hash : true,
        ]);

        return $result;
    }

    public function difference(AdUnit $adUnit): array
    {
        $adUnit->loadMissing(['site', 'sizes']);
        $connection = $this->connections->requireFor($adUnit->site);
        $attributes = $this->attributes($adUnit, $connection->configuration ?? []);
        $hash = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
        $mapping = $this->mapping($adUnit, $connection->id);

        return [
            'mapped' => (bool) $mapping,
            'remoteId' => $mapping?->remote_object_id,
            'localHash' => $hash,
            'remoteHash' => $mapping?->payload_hash,
            'different' => ! $mapping || $mapping->payload_hash !== $hash,
        ];
    }

    private function attributes(AdUnit $adUnit, array $connectionConfiguration): array
    {
        $rootAdUnitId = $connectionConfiguration['root_ad_unit_id'] ?? null;
        if (! $rootAdUnitId) {
            throw ValidationException::withMessages([
                'gam_connection' => 'The selected GAM connection must define configuration.root_ad_unit_id before inventory synchronization.',
            ]);
        }

        return [
            'parentId' => (string) $rootAdUnitId,
            'name' => $adUnit->name,
            'adUnitCode' => $adUnit->code,
            'description' => $adUnit->description,
            'targetWindow' => 'BLANK',
            'status' => $adUnit->is_enabled ? 'ACTIVE' : 'INACTIVE',
            'adUnitSizes' => $adUnit->sizes
                ->where('is_active', true)
                ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
                ->map(fn ($size) => [
                    'size' => ['width' => (int) $size->width, 'height' => (int) $size->height, 'isAspectRatio' => false],
                    'environmentType' => 'BROWSER',
                    'fullDisplayString' => $size->width.'x'.$size->height,
                ])->values()->all(),
        ];
    }

    private function mapping(AdUnit $adUnit, string $connectionId): ?GamRemoteObject
    {
        return GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connectionId)
            ->where('local_object_type', 'ad_unit')
            ->where('local_object_id', $adUnit->id)
            ->where('remote_object_type', 'ad_unit')
            ->first();
    }
}
