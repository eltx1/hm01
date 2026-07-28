<?php

namespace App\Services\Prebid;

use App\Enums\PrebidSetupStatus;
use App\Models\GamConnection;
use App\Models\GamRemoteObject;
use App\Models\PrebidError;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidSetupRun;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\Data\GamResult;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PrebidGamAutomationService
{
    public function __construct(
        private readonly PrebidGamTemplateFactory $templates,
        private readonly PrebidGamPlanBuilder $plans,
        private readonly GamConnectorManager $connectors,
        private readonly AuditRecorder $audit,
    ) {
    }

    /** @return array{run: PrebidSetupRun, confirmationToken: string} */
    public function preview(GamConnection $connection, User $actor, ?Site $site = null): array
    {
        $template = $this->templates->ensureForConnection($connection);
        $plan = $this->plans->build($connection, $template, $site);
        $token = Str::upper(Str::random(12));

        $run = PrebidSetupRun::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'gam_connection_id' => $connection->id,
            'prebid_gam_template_id' => $template->id,
            'site_id' => $site?->id,
            'initiated_by' => $actor->id,
            'status' => PrebidSetupStatus::Preview,
            'dry_run' => true,
            'confirmation_token_hash' => hash('sha256', $token),
            'plan' => $plan,
            'counters' => [
                'planned' => data_get($plan, 'estimates.pendingObjects', 0),
                'created' => 0,
                'skipped' => data_get($plan, 'estimates.existingObjects', 0),
                'failed' => 0,
            ],
            'cursor' => 0,
        ]);

        $this->audit->record('prebid.gam_setup.previewed', $connection->organization_id, $actor, $run, newValues: [
            'connection_id' => $connection->id,
            'site_id' => $site?->id,
            'estimates' => $plan['estimates'],
            'complete' => $plan['complete'],
        ]);

        return ['run' => $run->load('template', 'connection'), 'confirmationToken' => $token];
    }

    public function executeBatch(PrebidSetupRun $run, User $actor, ?string $confirmationToken = null, int $limit = 25): PrebidSetupRun
    {
        $run->loadMissing(['connection', 'template']);
        $this->confirm($run, $actor, $confirmationToken);

        if (! data_get($run->plan, 'complete', false)) {
            throw ValidationException::withMessages([
                'setup' => data_get($run->plan, 'missingPrerequisites', ['The setup plan is incomplete.']),
            ]);
        }

        if ($run->status === PrebidSetupStatus::Succeeded) {
            return $run;
        }

        $pending = collect(data_get($run->plan, 'pendingOperations', []));
        $cursor = (int) $run->cursor;
        $batch = $pending->slice($cursor, max(1, min($limit, 100)))->values();
        $counters = array_merge(['planned' => $pending->count(), 'created' => 0, 'skipped' => 0, 'failed' => 0], $run->counters ?? []);

        $run->update([
            'status' => PrebidSetupStatus::Running,
            'dry_run' => false,
            'started_at' => $run->started_at ?: now(),
        ]);

        foreach ($batch as $operation) {
            $existing = $this->mapping($run, $operation['key']);
            if ($existing && $existing->payload_hash === $operation['payloadHash']) {
                $counters['skipped']++;
                $cursor++;
                continue;
            }

            try {
                $result = $this->executeOperation($run, $operation);
                if (! $result->success) {
                    $this->recordFailure($run, $operation, $result);
                    $counters['failed']++;
                    $run->update([
                        'status' => $counters['created'] > 0 ? PrebidSetupStatus::PartiallySucceeded : PrebidSetupStatus::Failed,
                        'counters' => $counters,
                        'cursor' => $cursor,
                    ]);

                    return $run->fresh(['remoteObjects', 'errors']);
                }

                $remoteId = $this->remoteId($result, $run, $operation['key']);
                if (! $remoteId) {
                    throw new RuntimeException("GAM returned success without a remote ID for {$operation['key']}.");
                }

                PrebidGamRemoteObject::withoutGlobalScopes()->updateOrCreate(
                    ['gam_connection_id' => $run->gam_connection_id, 'object_key' => $operation['key']],
                    [
                        'organization_id' => $run->organization_id,
                        'prebid_gam_template_id' => $run->prebid_gam_template_id,
                        'prebid_setup_run_id' => $run->id,
                        'remote_object_type' => $operation['type'],
                        'remote_object_id' => $remoteId,
                        'payload_hash' => $operation['payloadHash'],
                        'remote_status' => data_get($result->data, 'status'),
                        'metadata' => ['operation_id' => $result->operationId, 'duplicate' => $result->duplicate],
                        'synced_at' => now(),
                    ],
                );
                $result->duplicate ? $counters['skipped']++ : $counters['created']++;
                $cursor++;
            } catch (\Throwable $exception) {
                PrebidError::withoutGlobalScopes()->create([
                    'organization_id' => $run->organization_id,
                    'site_id' => $run->site_id,
                    'gam_connection_id' => $run->gam_connection_id,
                    'prebid_setup_run_id' => $run->id,
                    'category' => 'EXECUTION',
                    'code' => 'LOCAL_EXECUTION_FAILURE',
                    'message' => mb_substr($exception->getMessage(), 0, 10000),
                    'retryable' => true,
                    'context' => ['object_key' => $operation['key'], 'type' => $operation['type']],
                    'occurred_at' => now(),
                ]);
                $counters['failed']++;
                $run->update([
                    'status' => $counters['created'] > 0 ? PrebidSetupStatus::PartiallySucceeded : PrebidSetupStatus::Failed,
                    'counters' => $counters,
                    'cursor' => $cursor,
                ]);

                return $run->fresh(['remoteObjects', 'errors']);
            }
        }

        $finished = $cursor >= $pending->count();
        $run->update([
            'status' => $finished ? PrebidSetupStatus::Succeeded : PrebidSetupStatus::PartiallySucceeded,
            'counters' => $counters,
            'cursor' => $cursor,
            'completed_at' => $finished ? now() : null,
        ]);

        if ($finished) {
            $this->audit->record('prebid.gam_setup.completed', $run->organization_id, $actor, $run, newValues: [
                'connection_id' => $run->gam_connection_id,
                'counters' => $counters,
            ]);
        }

        return $run->fresh(['remoteObjects', 'errors']);
    }

    private function confirm(PrebidSetupRun $run, User $actor, ?string $token): void
    {
        if ($run->confirmed_at) {
            return;
        }

        if (! $token || ! hash_equals((string) $run->confirmation_token_hash, hash('sha256', strtoupper(trim($token))))) {
            throw ValidationException::withMessages([
                'confirmation_token' => 'Enter the exact one-time confirmation code generated with the dry-run preview.',
            ]);
        }

        DB::transaction(function () use ($run, $actor): void {
            $run->update([
                'status' => PrebidSetupStatus::Confirmed,
                'confirmed_at' => now(),
                'confirmation_token_hash' => null,
            ]);
            $this->audit->record('prebid.gam_setup.confirmed', $run->organization_id, $actor, $run, newValues: [
                'connection_id' => $run->gam_connection_id,
                'confirmed_at' => now()->toIso8601String(),
            ]);
        });
    }

    private function executeOperation(PrebidSetupRun $run, array $operation): GamResult
    {
        $connector = $this->connectors->for($run->connection);
        $template = $run->template;
        $options = [
            'dry_run' => false,
            'local_type' => 'prebid_setup',
            'local_id' => $operation['key'],
            'remote_type' => $operation['type'],
            'remote_id_path' => 'rval.0.id',
            'idempotency_key' => hash('sha256', $run->gam_connection_id.'|'.$run->prebid_gam_template_id.'|'.$operation['key'].'|'.$operation['payloadHash']),
            'mapping_metadata' => ['prebid_template_id' => $run->prebid_gam_template_id, 'setup_run_id' => $run->id],
        ];

        return match ($operation['type']) {
            'company' => $connector->createCompany([
                'name' => $operation['data']['name'],
                'type' => 'ADVERTISER',
            ], $options),
            'targeting_key' => $connector->createCustomTargetingKey([
                'name' => $operation['data']['name'],
                'displayName' => 'Prebid '.strtoupper($operation['data']['name']),
                'type' => 'FREEFORM',
                'status' => 'ACTIVE',
            ], $options),
            'targeting_value' => $connector->createCustomTargetingValue([
                'customTargetingKeyId' => $this->remoteRequired($run, 'targeting-key:'.$operation['data']['key']),
                'name' => $operation['data']['value'],
                'displayName' => $operation['data']['value'],
                'matchType' => 'EXACT',
                'status' => 'ACTIVE',
            ], $options),
            'order' => $connector->createOrder([
                'name' => $operation['data']['name'],
                'advertiserId' => $this->remoteRequired($run, 'company'),
                'traffickerId' => (string) data_get($template->settings, 'trafficker_id'),
                'status' => 'DRAFT',
            ], $options),
            'creative' => $connector->createCreative([
                'advertiserId' => $this->remoteRequired($run, 'company'),
                'name' => 'Horus Prebid Universal '.$operation['data']['size']['width'].'x'.$operation['data']['size']['height'],
                'size' => [
                    'width' => $operation['data']['size']['width'],
                    'height' => $operation['data']['size']['height'],
                    'isAspectRatio' => false,
                ],
                'snippet' => $template->universal_creative_template,
                'isSafeFrameCompatible' => true,
            ], $options),
            'line_item' => $connector->createLineItem($this->lineItemPayload($run, $operation), $options),
            'association' => $connector->associateCreative([
                'lineItemId' => $this->remoteRequired($run, $operation['data']['line_item_key']),
                'creativeId' => $this->remoteRequired($run, $operation['data']['creative_key']),
            ], $options),
            default => throw new RuntimeException("Unsupported Prebid setup operation {$operation['type']}.")
        };
    }

    private function lineItemPayload(PrebidSetupRun $run, array $operation): array
    {
        $template = $run->template;
        $price = (float) $operation['data']['price'];
        $targetedAdUnits = collect($operation['data']['ad_unit_remote_ids'])
            ->map(fn ($id) => ['adUnitId' => (string) $id, 'includeDescendants' => false])
            ->values()->all();

        return [
            'orderId' => $this->remoteRequired($run, 'order'),
            'name' => $template->order_name_prefix.' $'.$operation['data']['price'],
            'startDateTimeType' => 'IMMEDIATELY',
            'unlimitedEndDateTime' => true,
            'lineItemType' => $template->line_item_type,
            'priority' => (int) $template->line_item_priority,
            'costType' => (string) data_get($template->settings, 'cost_type', 'CPM'),
            'costPerUnit' => [
                'currencyCode' => $template->currency,
                'microAmount' => (int) round($price * 1000000),
            ],
            'discountType' => 'PERCENTAGE',
            'discount' => 0,
            'creativeRotationType' => 'EVEN',
            'deliveryRateType' => (string) data_get($template->settings, 'delivery_rate_type', 'EVENLY'),
            'primaryGoal' => ['goalType' => 'NONE', 'unitType' => 'IMPRESSIONS', 'units' => 0],
            'creativePlaceholders' => collect($operation['data']['sizes'])->map(fn (array $size) => [
                'size' => ['width' => $size['width'], 'height' => $size['height'], 'isAspectRatio' => false],
                'expectedCreativeCount' => 1,
            ])->values()->all(),
            'targeting' => [
                'inventoryTargeting' => ['targetedAdUnits' => $targetedAdUnits],
                'customTargeting' => [
                    '__type' => 'CustomCriteriaSet',
                    'logicalOperator' => 'AND',
                    'children' => [[
                        '__type' => 'CustomCriteria',
                        'keyId' => $this->remoteRequired($run, 'targeting-key:hb_pb'),
                        'operator' => 'IS',
                        'valueIds' => [$this->remoteRequired($run, 'targeting-value:hb_pb:'.$operation['data']['price'])],
                    ]],
                ],
            ],
        ];
    }

    private function remoteRequired(PrebidSetupRun $run, string $key): string
    {
        $mapping = $this->mapping($run, $key);
        if (! $mapping) {
            throw new RuntimeException("Required GAM object {$key} has not been created yet.");
        }

        return $mapping->remote_object_id;
    }

    private function mapping(PrebidSetupRun $run, string $key): ?PrebidGamRemoteObject
    {
        return PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $run->gam_connection_id)
            ->where('prebid_gam_template_id', $run->prebid_gam_template_id)
            ->where('object_key', $key)
            ->first();
    }

    private function remoteId(GamResult $result, PrebidSetupRun $run, string $key): ?string
    {
        foreach (['id', 'rval.0.id', 'rval.id', 'results.0.id', 'value.id'] as $path) {
            $value = data_get($result->data, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        $generic = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $run->gam_connection_id)
            ->where('local_object_type', 'prebid_setup')
            ->where('local_object_id', $key)
            ->first();

        return $generic?->remote_object_id;
    }

    private function recordFailure(PrebidSetupRun $run, array $operation, GamResult $result): void
    {
        PrebidError::withoutGlobalScopes()->create([
            'organization_id' => $run->organization_id,
            'site_id' => $run->site_id,
            'gam_connection_id' => $run->gam_connection_id,
            'prebid_setup_run_id' => $run->id,
            'category' => $result->errorCategory ?? 'UPSTREAM',
            'code' => $result->errorCode,
            'message' => $result->errorMessage ?? 'Google Ad Manager setup operation failed.',
            'retryable' => in_array($result->errorCategory, ['NETWORK', 'RATE_LIMIT', 'QUOTA', 'UPSTREAM'], true),
            'context' => ['object_key' => $operation['key'], 'type' => $operation['type'], 'operation_id' => $result->operationId],
            'occurred_at' => now(),
        ]);
    }
}
