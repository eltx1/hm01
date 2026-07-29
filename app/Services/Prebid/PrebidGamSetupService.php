<?php

namespace App\Services\Prebid;

use App\Models\GamConnection;
use App\Models\PrebidError;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidSetupRun;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class PrebidGamSetupService
{
    public function __construct(
        private readonly PrebidGamSetupPlanner $planner,
        private readonly GamConnectorManager $connectors,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function preview(GamConnection $connection): array
    {
        return $this->planner->plan($connection);
    }

    public function start(GamConnection $connection, User $actor, bool $dryRun = true, bool $confirmed = false): PrebidSetupRun
    {
        if (! $dryRun && ! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_external_writes' => 'Administrator confirmation is required before writing Prebid objects to GAM.',
            ]);
        }

        $plan = $this->planner->plan($connection);
        if (($plan['issues'] ?? []) !== []) {
            throw ValidationException::withMessages(['prebid_setup' => implode(' ', $plan['issues'])]);
        }
        $run = PrebidSetupRun::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'gam_connection_id' => $connection->id,
            'status' => $dryRun ? 'DRY_RUN' : 'PENDING',
            'dry_run' => $dryRun,
            'confirmed_by' => $dryRun ? null : $actor->id,
            'estimated_objects' => $plan['estimatedObjects'],
            'completed_objects' => $plan['existingObjects'],
            'cursor' => 0,
            'planned_objects' => $plan['objects'],
            'started_at' => now(),
            'completed_at' => $dryRun ? now() : null,
        ]);
        $this->audit->record('prebid.gam_setup.started', $connection->organization_id, $actor, $run, [], $run->toArray(), ['pending_objects' => $plan['pendingObjects']]);

        return $dryRun ? $run : $this->execute($run, $actor);
    }

    public function resume(PrebidSetupRun $run, User $actor, bool $confirmed): PrebidSetupRun
    {
        if ($run->dry_run || ! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_external_writes' => 'Administrator confirmation is required to resume external GAM writes.',
            ]);
        }

        return $this->execute($run, $actor);
    }

    private function execute(PrebidSetupRun $run, User $actor): PrebidSetupRun
    {
        $connection = GamConnection::withoutGlobalScopes()->findOrFail($run->gam_connection_id);
        $connector = $this->connectors->for($connection);
        $objects = $run->planned_objects ?? [];
        $run->update(['status' => 'RUNNING', 'error_message' => null, 'started_at' => $run->started_at ?? now()]);

        try {
            for ($index = (int) $run->cursor; $index < count($objects); $index++) {
                $object = $objects[$index];
                $existing = PrebidGamRemoteObject::withoutGlobalScopes()
                    ->where('gam_connection_id', $connection->id)
                    ->where('idempotency_key', $object['key'])
                    ->first();

                if (! $existing) {
                    $payload = $this->resolveReferences($connection, $object['payload']);
                    $method = $object['method'];
                    if (! method_exists($connector, $method)) {
                        throw new RuntimeException("Unsupported GAM connector method {$method}.");
                    }
                    $result = $connector->{$method}($payload, [
                        'dry_run' => false,
                        'idempotency_key' => 'prebid:'.$connection->id.':'.$object['key'],
                        'local_type' => 'prebid_'.$object['type'],
                        'local_id' => $object['key'],
                        'remote_type' => $object['type'],
                        'remote_id_path' => 'rval.0.id',
                    ]);
                    if (! $result->success) {
                        throw new RuntimeException($result->errorMessage ?: 'GAM rejected the Prebid setup operation.');
                    }
                    $remoteId = data_get($result->data, 'rval.0.id') ?? data_get($result->data, 'id');
                    if (! is_scalar($remoteId) || (string) $remoteId === '') {
                        throw new RuntimeException('GAM did not return a remote object ID for '.$object['key'].'.');
                    }

                    PrebidGamRemoteObject::withoutGlobalScopes()->updateOrCreate(
                        ['gam_connection_id' => $connection->id, 'idempotency_key' => $object['key']],
                        [
                            'organization_id' => $connection->organization_id,
                            'prebid_setup_run_id' => $run->id,
                            'local_object_type' => 'prebid_'.$object['type'],
                            'local_object_id' => $object['key'],
                            'remote_object_type' => $object['type'],
                            'remote_object_id' => (string) $remoteId,
                            'payload_hash' => $object['payloadHash'],
                            'metadata' => ['method' => $method],
                            'synced_at' => now(),
                        ],
                    );
                }

                $run->update([
                    'cursor' => $index + 1,
                    'completed_objects' => PrebidGamRemoteObject::withoutGlobalScopes()
                        ->where('gam_connection_id', $connection->id)
                        ->whereIn('idempotency_key', collect($objects)->pluck('key')->all())
                        ->count(),
                ]);
            }

            $run->update(['status' => 'SUCCEEDED', 'completed_at' => now()]);
            $this->audit->record('prebid.gam_setup.completed', $connection->organization_id, $actor, $run, [], $run->fresh()->toArray());

            return $run->fresh();
        } catch (Throwable $exception) {
            DB::transaction(function () use ($run, $connection, $exception): void {
                $run->update(['status' => 'FAILED', 'error_message' => mb_substr($exception->getMessage(), 0, 10000)]);
                PrebidError::withoutGlobalScopes()->create([
                    'organization_id' => $connection->organization_id,
                    'gam_connection_id' => $connection->id,
                    'prebid_setup_run_id' => $run->id,
                    'category' => 'GAM_SETUP',
                    'code' => class_basename($exception),
                    'message' => mb_substr($exception->getMessage(), 0, 10000),
                    'context' => ['cursor' => $run->cursor],
                ]);
            });

            return $run->fresh();
        }
    }

    private function resolveReferences(GamConnection $connection, mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '@')) {
            $key = substr($value, 1);
            $mapping = PrebidGamRemoteObject::withoutGlobalScopes()
                ->where('gam_connection_id', $connection->id)
                ->where('idempotency_key', $key)
                ->first();
            if (! $mapping) {
                throw new RuntimeException("Prebid GAM dependency {$key} is incomplete.");
            }

            return $mapping->remote_object_id;
        }
        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn ($item) => $this->resolveReferences($connection, $item), $value);
    }
}
