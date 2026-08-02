<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignNetworkStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignApprovalLog;
use App\Models\CampaignCreative;
use App\Models\CampaignNetworkInstance;
use App\Models\GamRemoteObject;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class CampaignDeploymentService
{
    public function __construct(
        private readonly CampaignNetworkPlanner $planner,
        private readonly GamConnectorManager $connectors,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function deployCampaign(Campaign $campaign, User $actor, bool $dryRun = true, bool $confirmed = false): array
    {
        if (! $dryRun && ! $confirmed) {
            throw ValidationException::withMessages(['confirm_external_writes' => 'Administrator confirmation is required before writing campaign objects to GAM.']);
        }
        if (! in_array($campaign->status, [CampaignStatus::Approved, CampaignStatus::Scheduled, CampaignStatus::Active, CampaignStatus::Paused], true)) {
            throw ValidationException::withMessages(['status' => 'Approve the campaign before GAM deployment.']);
        }

        $preview = $this->planner->preview($campaign);
        $results = [];
        foreach ($campaign->networkInstances()->where('status', '!=', CampaignNetworkStatus::Completed->value)->get() as $instance) {
            $results[$instance->id] = $this->deployInstance($instance, $actor, $dryRun, $confirmed);
        }
        $this->audit->record('campaign.gam_deployment.executed', $campaign->organization_id, $actor, $campaign, [], [
            'dry_run' => $dryRun,
            'network_results' => $results,
        ]);

        return ['preview' => $preview, 'results' => $results];
    }

    public function deployInstance(CampaignNetworkInstance $instance, User $actor, bool $dryRun = false, bool $confirmed = false): array
    {
        if (! $dryRun && ! $confirmed) throw ValidationException::withMessages(['confirm_external_writes' => 'Confirm external GAM writes.']);
        $plan = $this->planner->plan($instance->fresh());
        if ($plan['issues'] !== []) {
            $instance->update(['status' => CampaignNetworkStatus::Failed, 'last_error' => implode(' ', $plan['issues'])]);
            return ['success' => false, 'dryRun' => $dryRun, 'issues' => $plan['issues'], 'completed' => $instance->completed_objects];
        }

        $connector = $this->connectors->for($instance->connection);
        $instance->update(['status' => $dryRun ? CampaignNetworkStatus::DryRun : CampaignNetworkStatus::Deploying, 'last_error' => null, 'cursor' => 0, 'completed_objects' => 0]);
        $completed = 0;
        try {
            foreach ($plan['objects'] as $index => $object) {
                $mapping = $this->mapping($instance, $object);
                if ($mapping && $mapping->payload_hash === $object['payloadHash']) {
                    $completed++;
                    $instance->update(['cursor' => $index + 1, 'completed_objects' => $completed]);
                    continue;
                }

                $payload = $this->resolveReferences($instance, $object['payload'], $dryRun);
                $payload = $this->materializeCreative($payload);
                $method = $object['createMethod'];
                if ($mapping && $object['updateMethod']) {
                    $method = $object['updateMethod'];
                    $payload['id'] = $mapping->remote_object_id;
                } elseif ($mapping) {
                    $this->archiveReplacedRemote($instance, $object, $mapping->remote_object_id, $dryRun);
                    $mapping->delete();
                }
                if (! method_exists($connector, $method)) throw new RuntimeException("The GAM connector does not support {$method}.");

                $operationKey = $object['idempotencyKey'].':'.substr($object['payloadHash'], 0, 16).':'.$method;
                $result = $connector->{$method}($payload, [
                    'dry_run' => $dryRun,
                    'idempotency_key' => $operationKey,
                    'local_type' => $object['localType'],
                    'local_id' => $object['localId'],
                    'remote_type' => $object['remoteType'],
                    'remote_id_path' => 'id',
                    'mapping_metadata' => ['campaign_id' => $instance->campaign_id, 'network_instance_id' => $instance->id, 'reference' => $object['reference']],
                ]);
                if (! $result->success) throw new RuntimeException($result->errorMessage ?: "GAM {$method} failed.");

                if (! $dryRun) $this->ensureMapping($instance, $object, $result->data, $operationKey);
                $completed++;
                $instance->update(['cursor' => $index + 1, 'completed_objects' => $completed]);
            }

            if (! $dryRun) $this->archiveReplacedForInstance($instance);
            if (! $dryRun && in_array($instance->campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Active], true)) {
                $this->synchronizeInstanceStatus($instance->fresh(), true);
            }
            $status = $dryRun ? CampaignNetworkStatus::DryRun : match ($instance->campaign->status) {
                CampaignStatus::Active => CampaignNetworkStatus::Active,
                CampaignStatus::Scheduled => CampaignNetworkStatus::Scheduled,
                CampaignStatus::Paused => CampaignNetworkStatus::Paused,
                default => CampaignNetworkStatus::Deployed,
            };
            $instance->update(['status' => $status, 'deployed_at' => $dryRun ? $instance->deployed_at : now(), 'last_synced_at' => now(), 'last_error' => null]);
            $this->log($instance, $actor, $dryRun ? 'GAM_DRY_RUN' : 'GAM_DEPLOYED', ['completed' => $completed, 'planned' => count($plan['objects'])]);
            return ['success' => true, 'dryRun' => $dryRun, 'completed' => $completed, 'planned' => count($plan['objects'])];
        } catch (Throwable $exception) {
            $instance->update([
                'status' => $completed > 0 ? CampaignNetworkStatus::Partial : CampaignNetworkStatus::Failed,
                'completed_objects' => $completed,
                'last_error' => mb_substr($exception->getMessage(), 0, 10000),
            ]);
            $this->log($instance, $actor, 'GAM_DEPLOYMENT_FAILED', ['completed' => $completed, 'error' => $exception->getMessage()]);
            return ['success' => false, 'dryRun' => $dryRun, 'completed' => $completed, 'error' => $exception->getMessage()];
        }
    }

    public function pauseAll(Campaign $campaign, User $actor): array
    {
        return $this->synchronizeAll($campaign, $actor, false);
    }

    public function resumeAll(Campaign $campaign, User $actor): array
    {
        return $this->synchronizeAll($campaign, $actor, true);
    }

    public function completeAll(Campaign $campaign, User $actor): array
    {
        return $this->synchronizeAll($campaign, $actor, false, CampaignNetworkStatus::Completed);
    }

    public function archiveReplacedCreatives(Campaign $campaign): void
    {
        $replaced = CampaignCreative::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('status', 'REPLACED')->get();
        foreach ($campaign->networkInstances as $instance) {
            foreach ($replaced as $creative) {
                $mapping = GamRemoteObject::withoutGlobalScopes()
                    ->where('gam_connection_id', $instance->gam_connection_id)
                    ->where('local_object_type', 'campaign_creative')
                    ->where('local_object_id', $creative->id)
                    ->where('remote_object_type', 'creative')->first();
                if ($mapping) $this->archiveReplacedRemote($instance, ['remoteType' => 'creative', 'idempotencyKey' => 'replace:'.$creative->id], $mapping->remote_object_id, false);
            }
        }
    }

    private function archiveReplacedForInstance(CampaignNetworkInstance $instance): void
    {
        $replaced = CampaignCreative::withoutGlobalScopes()
            ->where('campaign_id', $instance->campaign_id)
            ->where('status', 'REPLACED')
            ->get();
        foreach ($replaced as $creative) {
            $mapping = GamRemoteObject::withoutGlobalScopes()
                ->where('gam_connection_id', $instance->gam_connection_id)
                ->where('local_object_type', 'campaign_creative')
                ->where('local_object_id', $creative->id)
                ->where('remote_object_type', 'creative')
                ->first();
            if ($mapping) $this->archiveReplacedRemote($instance, ['remoteType' => 'creative', 'idempotencyKey' => 'replace:'.$creative->id], $mapping->remote_object_id, false);
        }
    }

    private function synchronizeAll(Campaign $campaign, User $actor, bool $activate, ?CampaignNetworkStatus $forced = null): array
    {
        $results = [];
        foreach ($campaign->networkInstances as $instance) {
            try {
                $this->synchronizeInstanceStatus($instance, $activate);
                $status = $forced ?? ($activate ? ($campaign->status === CampaignStatus::Scheduled ? CampaignNetworkStatus::Scheduled : CampaignNetworkStatus::Active) : CampaignNetworkStatus::Paused);
                $instance->update(['status' => $status, 'remote_status' => $activate ? 'READY' : 'PAUSED', 'last_synced_at' => now(), 'last_error' => null]);
                $results[$instance->id] = true;
            } catch (Throwable $exception) {
                $instance->update(['status' => CampaignNetworkStatus::Failed, 'last_error' => $exception->getMessage()]);
                $results[$instance->id] = false;
            }
        }
        $this->log($campaign->networkInstances->first(), $actor, $activate ? 'REMOTE_RESUMED' : 'REMOTE_PAUSED', ['results' => $results]);
        return $results;
    }

    private function synchronizeInstanceStatus(CampaignNetworkInstance $instance, bool $activate): void
    {
        $mapping = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $instance->gam_connection_id)
            ->where('local_object_type', 'campaign_network_instance')
            ->where('local_object_id', $instance->id)
            ->where('remote_object_type', 'line_item')->first();
        if (! $mapping) throw new RuntimeException('The network line item has not been deployed.');
        $connector = $this->connectors->for($instance->connection);
        $statement = ['query' => 'WHERE id = :id', 'values' => [['key' => 'id', 'value' => ['__type' => 'NumberValue', 'value' => $mapping->remote_object_id]]]];
        $method = $activate
            ? ($instance->remote_status === 'PAUSED' ? 'resumeLineItem' : 'activateLineItem')
            : 'pauseLineItem';
        $result = $connector->{$method}($statement, [
            'dry_run' => false,
            'idempotency_key' => 'campaign-status:'.hash('sha256', $instance->id.'|'.$method.'|'.$instance->campaign->updated_at?->timestamp),
        ]);
        if (! $result->success) throw new RuntimeException($result->errorMessage ?: "Could not {$method}.");
    }

    private function resolveReferences(CampaignNetworkInstance $instance, mixed $value, bool $dryRun): mixed
    {
        if (is_string($value) && str_starts_with($value, '@')) {
            if ($dryRun) return 'DRY_RUN_'.substr($value, 1);
            $reference = substr($value, 1);
            $planObject = collect($instance->deployment_plan['objects'] ?? [])->firstWhere('reference', $reference);
            if (! $planObject) throw new RuntimeException("Unknown campaign plan reference {$reference}.");
            $mapping = $this->mapping($instance, $planObject);
            if (! $mapping) throw new RuntimeException("The GAM dependency {$reference} is incomplete for this network.");
            return $mapping->remote_object_id;
        }
        if (! is_array($value)) return $value;
        return array_map(fn ($item) => $this->resolveReferences($instance, $item, $dryRun), $value);
    }

    private function materializeCreative(array $payload): array
    {
        $file = $payload['_file'] ?? null;
        unset($payload['_file']);
        if (! is_array($file) || blank($file['path'] ?? null)) return $payload;
        $contents = Storage::disk($file['disk'] ?? 'local')->get($file['path']);
        $asset = [
            'fileName' => $file['original_name'] ?? basename($file['path']),
            'assetByteArray' => base64_encode($contents),
        ];
        if (($payload['__type'] ?? null) === 'Html5Creative') $payload['html5Asset'] = $asset;
        else $payload['primaryImageAsset'] = $asset;
        return $payload;
    }

    private function archiveReplacedRemote(CampaignNetworkInstance $instance, array $object, string $remoteId, bool $dryRun): void
    {
        if (($object['remoteType'] ?? null) !== 'creative') return;
        $connector = $this->connectors->for($instance->connection);
        $result = $connector->archiveObject([
            'service' => 'CreativeService',
            'method' => 'performCreativeAction',
            'action_type' => 'ArchiveCreatives',
            'filter_statement' => ['query' => 'WHERE id = :id', 'values' => [['key' => 'id', 'value' => ['__type' => 'NumberValue', 'value' => $remoteId]]]],
        ], [
            'dry_run' => $dryRun,
            'idempotency_key' => 'campaign-archive:'.hash('sha256', $instance->id.'|'.$remoteId.'|'.($object['idempotencyKey'] ?? 'creative')),
        ]);
        if (! $result->success) throw new RuntimeException($result->errorMessage ?: 'The replaced creative could not be archived.');
    }

    private function mapping(CampaignNetworkInstance $instance, array $object): ?GamRemoteObject
    {
        return GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $instance->gam_connection_id)
            ->where('local_object_type', $object['localType'])
            ->where('local_object_id', $object['localId'])
            ->where('remote_object_type', $object['remoteType'])
            ->first();
    }

    private function ensureMapping(CampaignNetworkInstance $instance, array $object, array $data, string $operationKey): void
    {
        $remoteId = data_get($data, 'rval.0.id') ?? data_get($data, 'id') ?? data_get($data, 'value.0.id');
        $mapping = $this->mapping($instance, $object);
        if (! $mapping && (! is_scalar($remoteId) || (string) $remoteId === '')) throw new RuntimeException('GAM did not return a remote ID for '.$object['reference'].'.');
        GamRemoteObject::withoutGlobalScopes()->updateOrCreate(
            [
                'gam_connection_id' => $instance->gam_connection_id,
                'local_object_type' => $object['localType'],
                'local_object_id' => $object['localId'],
                'remote_object_type' => $object['remoteType'],
            ],
            [
                'organization_id' => $instance->connection->organization_id,
                'remote_object_id' => (string) ($remoteId ?: $mapping->remote_object_id),
                'idempotency_key' => $operationKey,
                'payload_hash' => $object['payloadHash'],
                'remote_status' => data_get($data, 'rval.0.status') ?? data_get($data, 'status'),
                'metadata' => ['campaign_id' => $instance->campaign_id, 'network_instance_id' => $instance->id, 'reference' => $object['reference']],
                'synced_at' => now(),
            ],
        );
    }

    private function log(?CampaignNetworkInstance $instance, User $actor, string $action, array $metadata): void
    {
        if (! $instance) return;
        CampaignApprovalLog::withoutGlobalScopes()->create([
            'organization_id' => $instance->campaign->organization_id,
            'campaign_id' => $instance->campaign_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'metadata' => array_merge(['network_instance_id' => $instance->id, 'gam_connection_id' => $instance->gam_connection_id], $metadata),
            'created_at' => now(),
        ]);
    }
}
