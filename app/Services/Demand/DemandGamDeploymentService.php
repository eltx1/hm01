<?php

namespace App\Services\Demand;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\DemandSyncStatus;
use App\Models\DemandError;
use App\Models\DemandPlacement;
use App\Models\DemandRemoteObject;
use App\Models\DemandSite;
use App\Models\DemandSyncLog;
use App\Models\GamRemoteObject;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\GamConnectionResolver;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class DemandGamDeploymentService
{
    public function __construct(
        private readonly GamConnectionResolver $connections,
        private readonly GamConnectorManager $gamConnectors,
        private readonly DemandConnectorManager $demandConnectors,
        private readonly DemandPayloadSanitizer $sanitizer,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function preview(DemandSite $site): array
    {
        return $this->plan($site);
    }

    public function deploy(
        DemandSite $site,
        User $actor,
        bool $dryRun = true,
        bool $confirmed = false,
    ): array {
        if (! $dryRun && ! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_external_writes' => 'Administrator confirmation is required before writing native demand objects to GAM.',
            ]);
        }

        $plan = $this->plan($site);
        if ($plan['issues'] !== []) {
            return ['success' => false, 'dryRun' => $dryRun, 'plan' => $plan, 'completed' => 0];
        }

        if ($dryRun) {
            $this->log($site, null, 'GAM_DRY_RUN', true, $plan, 'Native demand GAM deployment planned without external writes.');
            $this->audit->record('demand.gam.dry_run', $site->organization_id, $actor, $site, newValues: [
                'connection_id' => $plan['connectionId'],
                'objects' => count($plan['objects']),
            ]);

            return ['success' => true, 'dryRun' => true, 'plan' => $plan, 'completed' => 0];
        }

        $connection = $this->connections->requireFor($site->site);
        $connector = $this->gamConnectors->for($connection);
        $completed = 0;

        try {
            foreach ($plan['objects'] as $object) {
                $mapping = $this->mapping($site, $connection->id, $object);
                if ($mapping && hash_equals((string) $mapping->payload_hash, $object['payloadHash'])) {
                    $completed++;
                    continue;
                }

                $payload = $this->resolveReferences($site, $connection->id, $plan, $object['payload']);
                $method = $object['createMethod'];

                if ($mapping && $object['updateMethod']) {
                    $method = $object['updateMethod'];
                    $payload['id'] = $mapping->remote_object_id;
                } elseif ($mapping) {
                    if ($object['remoteType'] === 'creative') {
                        $this->archiveCreative($connector, $mapping->remote_object_id, $object);
                    } elseif ($object['remoteType'] === 'creative_association') {
                        $this->deleteAssociation($connector, $mapping, $object);
                    }
                    $mapping->delete();
                }

                if (! method_exists($connector, $method)) {
                    throw new RuntimeException("The GAM connector does not support {$method}.");
                }

                $operationKey = hash('sha256', $connection->id.'|'.$object['idempotencyKey'].'|'.$object['payloadHash'].'|'.$method);
                $result = $connector->{$method}($payload, [
                    'dry_run' => false,
                    'idempotency_key' => $operationKey,
                ]);

                if (! $result->success) {
                    throw new RuntimeException($result->errorMessage ?: "GAM {$method} failed.");
                }

                $this->ensureMapping($site, $connection->id, $object, $result->data, $operationKey, $payload);
                $completed++;
            }

            $site->update([
                'sync_status' => DemandSyncStatus::InSync,
                'last_synced_at' => now(),
                'updated_by' => $actor->id,
            ]);

            $this->log($site, null, 'GAM_DEPLOYED', false, ['completed' => $completed], 'Native demand GAM objects deployed.');
            $this->audit->record('demand.gam.deployed', $site->organization_id, $actor, $site, newValues: [
                'connection_id' => $connection->id,
                'completed' => $completed,
            ]);

            return ['success' => true, 'dryRun' => false, 'plan' => $plan, 'completed' => $completed];
        } catch (Throwable $exception) {
            $site->update(['sync_status' => DemandSyncStatus::Failed, 'updated_by' => $actor->id]);
            $this->log($site, null, 'GAM_DEPLOYMENT_FAILED', false, ['completed' => $completed, 'error' => $exception->getMessage()], $exception->getMessage());

            return [
                'success' => false,
                'dryRun' => false,
                'plan' => $plan,
                'completed' => $completed,
                'error' => $exception->getMessage(),
            ];
        }
    }

    public function pause(DemandPlacement $placement, User $actor): bool
    {
        return $this->synchronizeStatus($placement, $actor, false);
    }

    public function resume(DemandPlacement $placement, User $actor): bool
    {
        return $this->synchronizeStatus($placement, $actor, true);
    }

    private function plan(DemandSite $site): array
    {
        $site->loadMissing([
            'account.network',
            'site',
            'placements.placement.adUnit',
            'placements.placement.sizes',
            'placements.widgets',
        ]);
        $issues = [];

        if (! $site->is_enabled || $site->approval_status !== DemandApprovalStatus::Approved) {
            $issues[] = 'Approve and enable the demand website mapping before GAM deployment.';
        }
        if (! $site->account->is_enabled || $site->account->approval_status !== DemandApprovalStatus::Approved) {
            $issues[] = 'Approve and enable the demand account before GAM deployment.';
        }

        $connection = null;
        try {
            $connection = $this->connections->requireFor($site->site);
        } catch (Throwable $exception) {
            $issues[] = $exception->getMessage();
        }

        $placements = $site->placements->filter(function (DemandPlacement $placement): bool {
            $mode = $placement->integration_mode
                ?? $placement->demandSite->integration_mode
                ?? $placement->demandSite->account->integration_mode;

            return $placement->is_enabled
                && $placement->approval_status === DemandApprovalStatus::Approved
                && in_array($mode, [
                    DemandIntegrationMode::GamThirdPartyCreative,
                    DemandIntegrationMode::GamLineItem,
                ], true);
        })->values();

        if ($placements->isEmpty()) {
            $issues[] = 'No approved placement is configured for GAM third-party creative or GAM line-item mode.';
        }

        $remoteAdUnits = collect();
        if ($connection) {
            $adUnitIds = $placements->pluck('placement.ad_unit_id')->filter()->unique()->values();
            $remoteAdUnits = GamRemoteObject::withoutGlobalScopes()
                ->where('gam_connection_id', $connection->id)
                ->where('local_object_type', 'ad_unit')
                ->where('remote_object_type', 'ad_unit')
                ->whereIn('local_object_id', $adUnitIds)
                ->pluck('remote_object_id', 'local_object_id');

            if ($adUnitIds->diff($remoteAdUnits->keys())->isNotEmpty()) {
                $issues[] = 'Synchronize every selected placement ad unit to the website GAM connection before native deployment.';
            }
        }

        $objects = [];
        $account = $site->account;
        $networkCode = $account->network->code->value;

        $objects[] = $this->object(
            'demand_account',
            $account->id,
            'company',
            'createCompany',
            'updateCompany',
            [
                'name' => config('demand.gam.company_name_prefix', 'Horus Native').' · '.$networkCode.' · '.$account->name,
                'type' => 'ADVERTISER',
                'externalId' => $account->public_key,
            ],
            'company',
        );

        $objects[] = $this->object(
            'demand_site',
            $site->id,
            'order',
            'createOrder',
            'updateOrder',
            [
                'name' => config('demand.gam.order_name_prefix', 'Horus Native').' · '.$site->site->display_name.' · '.$networkCode,
                'advertiserId' => '@company',
                'status' => 'DRAFT',
                'notes' => 'Managed by Horus Media native demand account '.$account->public_key,
            ],
            'order',
        );

        foreach ($placements as $placement) {
            $adUnitId = $remoteAdUnits->get($placement->placement->ad_unit_id);
            $sizes = $placement->placement->sizes
                ->where('is_active', true)
                ->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
                ->map(fn ($size) => [(int) $size->width, (int) $size->height])
                ->unique(fn ($size) => implode('x', $size))
                ->values();
            if ($sizes->isEmpty()) {
                $sizes = collect([[1, 1]]);
            }

            $lineReference = 'line_item:'.$placement->id;
            $objects[] = $this->object(
                'demand_placement',
                $placement->id,
                'line_item',
                'createLineItem',
                'updateLineItem',
                [
                    'name' => 'Horus Native · '.$networkCode.' · '.$placement->placement->name,
                    'orderId' => '@order',
                    'lineItemType' => data_get($account->configuration, 'gam_line_item_type', config('demand.gam.line_item_type', 'PRICE_PRIORITY')),
                    'priority' => (int) data_get($placement->configuration, 'gam_priority', 12),
                    'costType' => config('demand.gam.cost_type', 'CPM'),
                    'costPerUnit' => [
                        'currencyCode' => (string) data_get($account->configuration, 'currency', 'USD'),
                        'microAmount' => (int) data_get($placement->configuration, 'cpm_micros', config('demand.gam.default_cpm_micros', 0)),
                    ],
                    'primaryGoal' => ['goalType' => 'NONE'],
                    'creativePlaceholders' => $sizes
                        ->map(fn ($size) => ['size' => ['width' => $size[0], 'height' => $size[1], 'isAspectRatio' => false]])
                        ->all(),
                    'targeting' => [
                        'inventoryTargeting' => [
                            'targetedAdUnits' => $adUnitId
                                ? [['adUnitId' => (string) $adUnitId, 'includeDescendants' => false]]
                                : [],
                        ],
                    ],
                    'startDateTimeType' => 'IMMEDIATELY',
                    'unlimitedEndDateTime' => true,
                ],
                $lineReference,
            );

            $creativeDefinition = $this->demandConnectors->for($account)->generateGamCreative($placement);
            $widget = $placement->widgets
                ->first(fn ($candidate) => $candidate->is_enabled && $candidate->approval_status === DemandApprovalStatus::Approved);
            $creativeLocalId = $widget?->id ?? $placement->id;
            $creativeLocalType = $widget ? 'demand_widget' : 'demand_placement_creative';
            $creativeReference = 'creative:'.$creativeLocalId;

            $creativeObject = $this->object(
                $creativeLocalType,
                $creativeLocalId,
                'creative',
                'createCreative',
                null,
                [
                    '__type' => 'ThirdPartyCreative',
                    'name' => $creativeDefinition['name'],
                    'advertiserId' => '@company',
                    'size' => $creativeDefinition['size'],
                    'snippet' => $creativeDefinition['snippet'],
                    'isSafeFrameCompatible' => (bool) ($creativeDefinition['safeFrameCompatible'] ?? true),
                ],
                $creativeReference,
            );
            $objects[] = $creativeObject;

            $associationId = substr(hash('sha256', $placement->id.'|'.$creativeLocalId), 0, 64);
            $objects[] = $this->object(
                'demand_creative_association',
                $associationId,
                'creative_association',
                'associateCreative',
                null,
                [
                    'lineItemId' => '@'.$lineReference,
                    'creativeId' => '@'.$creativeReference,
                ],
                'association:'.$associationId,
                ['creative_payload_hash' => $creativeObject['payloadHash']],
            );
        }

        $existing = collect();
        if ($connection) {
            $existing = DemandRemoteObject::withoutGlobalScopes()
                ->where('demand_account_id', $account->id)
                ->where('connection_key', $connection->id)
                ->get()
                ->keyBy(fn (DemandRemoteObject $mapping) => $mapping->local_object_type.'|'.$mapping->local_object_id.'|'.$mapping->remote_object_type);
        }

        $pending = collect($objects)->filter(function (array $object) use ($existing): bool {
            $mapping = $existing->get($object['localType'].'|'.$object['localId'].'|'.$object['remoteType']);

            return ! $mapping || $mapping->payload_hash !== $object['payloadHash'];
        })->count();

        return [
            'demandSiteId' => $site->id,
            'connectionId' => $connection?->id,
            'connectionName' => $connection?->name,
            'networkCode' => $connection?->network_code,
            'estimatedObjects' => count($objects),
            'existingObjects' => count($objects) - $pending,
            'pendingObjects' => $pending,
            'issues' => array_values(array_unique($issues)),
            'objects' => $objects,
        ];
    }

    private function synchronizeStatus(DemandPlacement $placement, User $actor, bool $activate): bool
    {
        $placement->loadMissing(['demandSite.site', 'demandSite.account.network']);
        $connection = $this->connections->requireFor($placement->demandSite->site);
        $mapping = DemandRemoteObject::withoutGlobalScopes()
            ->where('demand_account_id', $placement->demandSite->demand_account_id)
            ->where('connection_key', $connection->id)
            ->where('local_object_type', 'demand_placement')
            ->where('local_object_id', $placement->id)
            ->where('remote_object_type', 'line_item')
            ->first();

        if (! $mapping) {
            throw ValidationException::withMessages(['placement' => 'The demand placement line item has not been deployed to GAM.']);
        }

        $statement = [
            'query' => 'WHERE id = :id',
            'values' => [[
                'key' => 'id',
                'value' => ['__type' => 'NumberValue', 'value' => $mapping->remote_object_id],
            ]],
        ];
        $connector = $this->gamConnectors->for($connection);
        $method = $activate ? 'activateLineItem' : 'pauseLineItem';
        $result = $connector->{$method}($statement, [
            'dry_run' => false,
            'idempotency_key' => hash('sha256', 'demand-status|'.$placement->id.'|'.$method.'|'.$placement->updated_at?->timestamp),
        ]);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?: "GAM {$method} failed.");
        }

        $placement->update([
            'is_enabled' => $activate,
            'sync_status' => $activate ? DemandSyncStatus::InSync : DemandSyncStatus::Paused,
            'last_synced_at' => now(),
            'updated_by' => $actor->id,
        ]);
        $mapping->update(['remote_status' => $activate ? 'READY' : 'PAUSED', 'synced_at' => now()]);

        $this->audit->record($activate ? 'demand.gam.resumed' : 'demand.gam.paused', $placement->organization_id, $actor, $placement, newValues: [
            'gam_connection_id' => $connection->id,
            'remote_line_item_id' => $mapping->remote_object_id,
        ]);

        return true;
    }

    private function object(
        string $localType,
        string $localId,
        string $remoteType,
        string $createMethod,
        ?string $updateMethod,
        array $payload,
        string $reference,
        array $hashContext = [],
    ): array {
        $payloadHash = hash('sha256', json_encode([
            'payload' => $payload,
            'context' => $hashContext,
        ], JSON_THROW_ON_ERROR));

        return [
            'localType' => $localType,
            'localId' => $localId,
            'remoteType' => $remoteType,
            'createMethod' => $createMethod,
            'updateMethod' => $updateMethod,
            'payload' => $payload,
            'payloadHash' => $payloadHash,
            'idempotencyKey' => 'demand:'.$localType.':'.$localId.':'.$remoteType,
            'reference' => $reference,
        ];
    }

    private function resolveReferences(DemandSite $site, string $connectionId, array $plan, mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '@')) {
            $reference = substr($value, 1);
            $object = collect($plan['objects'])->firstWhere('reference', $reference);
            if (! $object) {
                throw new RuntimeException("Unknown demand deployment reference {$reference}.");
            }
            $mapping = $this->mapping($site, $connectionId, $object);
            if (! $mapping) {
                throw new RuntimeException("The GAM dependency {$reference} is incomplete.");
            }

            return $mapping->remote_object_id;
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn ($item) => $this->resolveReferences($site, $connectionId, $plan, $item), $value);
    }

    private function mapping(DemandSite $site, string $connectionId, array $object): ?DemandRemoteObject
    {
        return DemandRemoteObject::withoutGlobalScopes()
            ->where('demand_account_id', $site->demand_account_id)
            ->where('connection_key', $connectionId)
            ->where('local_object_type', $object['localType'])
            ->where('local_object_id', $object['localId'])
            ->where('remote_object_type', $object['remoteType'])
            ->first();
    }

    private function ensureMapping(
        DemandSite $site,
        string $connectionId,
        array $object,
        array $data,
        string $operationKey,
        array $resolvedPayload,
    ): void {
        $associationMetadata = [];
        if ($object['remoteType'] === 'creative_association') {
            $lineItemId = data_get($data, 'rval.0.lineItemId') ?? data_get($data, 'lineItemId') ?? ($resolvedPayload['lineItemId'] ?? null);
            $creativeId = data_get($data, 'rval.0.creativeId') ?? data_get($data, 'creativeId') ?? ($resolvedPayload['creativeId'] ?? null);
            if (! is_scalar($lineItemId) || ! is_scalar($creativeId)) {
                throw new RuntimeException('GAM returned an association without line-item and creative identifiers.');
            }
            $associationMetadata = [
                'line_item_id' => (string) $lineItemId,
                'creative_id' => (string) $creativeId,
            ];
            $remoteId = (string) $lineItemId.':'.(string) $creativeId;
        } else {
            $remoteId = data_get($data, 'id')
            ?? data_get($data, 'rval.0.id')
            ?? data_get($data, 'remoteId')
            ?? data_get($data, 'value.id');
        }

        if (! is_scalar($remoteId) || (string) $remoteId === '') {
            $existingGamMapping = GamRemoteObject::withoutGlobalScopes()
                ->where('gam_connection_id', $connectionId)
                ->where('local_object_type', $object['localType'])
                ->where('local_object_id', $object['localId'])
                ->where('remote_object_type', $object['remoteType'])
                ->first();
            $remoteId = $existingGamMapping?->remote_object_id;
        }

        if (! is_scalar($remoteId) || (string) $remoteId === '') {
            throw new RuntimeException('GAM returned success without a remote object ID.');
        }

        DemandRemoteObject::withoutGlobalScopes()->updateOrCreate(
            [
                'demand_account_id' => $site->demand_account_id,
                'connection_key' => $connectionId,
                'local_object_type' => $object['localType'],
                'local_object_id' => $object['localId'],
                'remote_object_type' => $object['remoteType'],
            ],
            [
                'organization_id' => $site->organization_id,
                'gam_connection_id' => $connectionId,
                'remote_object_id' => (string) $remoteId,
                'idempotency_key' => $operationKey,
                'payload_hash' => $object['payloadHash'],
                'remote_status' => data_get($data, 'status'),
                'metadata' => array_merge([
                    'demand_site_id' => $site->id,
                    'reference' => $object['reference'],
                ], $associationMetadata),
                'synced_at' => now(),
            ],
        );
    }

    private function archiveCreative($connector, string $remoteId, array $object): void
    {
        $result = $connector->archiveObject([
            'service' => 'CreativeService',
            'method' => 'performCreativeAction',
            'action_type' => 'ArchiveCreatives',
            'filter_statement' => [
                'query' => 'WHERE id = :id',
                'values' => [[
                    'key' => 'id',
                    'value' => ['__type' => 'NumberValue', 'value' => $remoteId],
                ]],
            ],
        ], [
            'dry_run' => false,
            'idempotency_key' => hash('sha256', 'demand-archive|'.$remoteId.'|'.$object['payloadHash']),
        ]);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?: 'The replaced native creative could not be archived.');
        }
    }


    private function deleteAssociation($connector, DemandRemoteObject $mapping, array $object): void
    {
        $lineItemId = data_get($mapping->metadata, 'line_item_id');
        $creativeId = data_get($mapping->metadata, 'creative_id');
        if ((! $lineItemId || ! $creativeId) && str_contains($mapping->remote_object_id, ':')) {
            [$lineItemId, $creativeId] = explode(':', $mapping->remote_object_id, 2);
        }
        if (! $lineItemId || ! $creativeId) {
            throw new RuntimeException('The existing creative association mapping is incomplete and cannot be replaced safely.');
        }

        $result = $connector->archiveObject([
            'service' => 'LineItemCreativeAssociationService',
            'method' => 'performLineItemCreativeAssociationAction',
            'action_type' => 'DeleteLineItemCreativeAssociations',
            'filter_statement' => [
                'query' => 'WHERE lineItemId = :lineItemId AND creativeId = :creativeId',
                'values' => [
                    ['key' => 'lineItemId', 'value' => ['__type' => 'NumberValue', 'value' => (string) $lineItemId]],
                    ['key' => 'creativeId', 'value' => ['__type' => 'NumberValue', 'value' => (string) $creativeId]],
                ],
            ],
        ], [
            'dry_run' => false,
            'idempotency_key' => hash('sha256', 'demand-association-delete|'.$mapping->remote_object_id.'|'.$object['payloadHash']),
        ]);

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?: 'The replaced native creative association could not be removed.');
        }
    }

    private function log(
        DemandSite $site,
        ?DemandPlacement $placement,
        string $action,
        bool $dryRun,
        array $payload,
        string $message,
    ): void {
        DemandSyncLog::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'demand_account_id' => $site->demand_account_id,
            'demand_site_id' => $site->id,
            'demand_placement_id' => $placement?->id,
            'level' => str_contains($action, 'FAILED') ? 'ERROR' : 'INFO',
            'action' => $action,
            'dry_run' => $dryRun,
            'response_payload' => $this->sanitizer->sanitize($payload),
            'message' => $message,
            'created_at' => now(),
        ]);

        if (str_contains($action, 'FAILED')) {
            DemandError::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id,
                'demand_account_id' => $site->demand_account_id,
                'demand_site_id' => $site->id,
                'demand_placement_id' => $placement?->id,
                'category' => 'GAM_DEPLOYMENT',
                'code' => $action,
                'message' => mb_substr($message, 0, 10000),
                'retryable' => true,
                'context' => $this->sanitizer->sanitize($payload),
                'occurred_at' => now(),
            ]);
        }
    }
}
