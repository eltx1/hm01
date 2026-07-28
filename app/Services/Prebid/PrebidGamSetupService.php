<?php

namespace App\Services\Prebid;

use App\Models\GamConnection;
use App\Models\PrebidError;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidPriceBucket;
use App\Models\PrebidSetupRun;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Gam\Data\GamResult;
use App\Services\Gam\GamConnectorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class PrebidGamSetupService
{
    public function __construct(
        private readonly GamConnectorManager $connectors,
        private readonly PrebidPriceBucketService $priceBuckets,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function ensureTemplate(GamConnection $connection, User $actor): PrebidGamTemplate
    {
        $bucket = PrebidPriceBucket::withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            ->where('enabled', true)
            ->orderByDesc('is_default')
            ->first();
        $bucket ??= PrebidPriceBucket::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $connection->organization_id, 'code' => 'standard-usd'],
            [
                'name' => 'Standard USD 0.05',
                'currency_code' => 'USD',
                'granularity' => 'CUSTOM',
                'ranges' => $this->priceBuckets->defaultRanges(),
                'is_default' => true,
                'enabled' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ],
        );

        return PrebidGamTemplate::withoutGlobalScopes()->firstOrCreate(
            ['gam_connection_id' => $connection->id],
            [
                'organization_id' => $connection->organization_id,
                'prebid_price_bucket_id' => $bucket->id,
                'name' => $connection->name.' centralized Prebid',
                'enabled' => true,
                'advertiser_name' => 'Horus Media Prebid',
                'order_prefix' => 'Horus Prebid',
                'line_item_prefix' => 'HB',
                'creative_prefix' => 'Horus Universal Creative',
                'targeting_keys' => [
                    'hb_pb' => ['displayName' => 'Prebid price bucket', 'type' => 'PREDEFINED'],
                    'hb_adid' => ['displayName' => 'Prebid ad ID', 'type' => 'FREEFORM'],
                    'hb_bidder' => ['displayName' => 'Prebid bidder', 'type' => 'FREEFORM'],
                    'hb_format' => ['displayName' => 'Prebid media format', 'type' => 'PREDEFINED'],
                ],
                'targeting_values' => ['hb_format' => ['banner', 'native', 'video']],
                'creative_sizes' => config('prebid.default_creative_sizes', [[1, 1]]),
                'max_line_items_per_order' => 450,
                'priority' => 12,
                'currency_code' => 'USD',
                'universal_creative_snippet' => $this->universalCreativeSnippet(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ],
        )->load(['connection', 'priceBucket']);
    }

    public function preview(PrebidGamTemplate $template, User $actor): PrebidSetupRun
    {
        $template->loadMissing(['connection', 'priceBucket']);
        $plan = $this->plan($template);
        $existing = $this->existingKeys($template->connection, $plan['steps']);
        $missing = array_values(array_filter($plan['steps'], fn (array $step): bool => ! isset($existing[$this->stepIdentity($step)])));

        $run = PrebidSetupRun::withoutGlobalScopes()->create([
            'organization_id' => $template->organization_id,
            'gam_connection_id' => $template->gam_connection_id,
            'prebid_gam_template_id' => $template->id,
            'initiated_by' => $actor->id,
            'status' => 'PREVIEW',
            'dry_run' => true,
            'confirmed' => false,
            'estimated_objects' => count($missing),
            'counters' => [
                'total' => count($plan['steps']),
                'existing' => count($plan['steps']) - count($missing),
                'remaining' => count($missing),
                'created' => 0,
                'skipped' => 0,
                'failed' => 0,
                'processed' => 0,
                'by_type' => [],
            ],
            'cursor' => ['offset' => 0, 'last_key' => null],
            'plan' => $plan,
            'metadata' => [
                'requirements' => $this->requirements($template->connection),
                'incomplete' => $this->incomplete($template, $plan),
            ],
        ]);

        $this->audit->record('prebid.gam_setup.previewed', $template->organization_id, $actor, $run, newValues: [
            'gam_connection_id' => $template->gam_connection_id,
            'estimated_objects' => $run->estimated_objects,
            'missing_requirements' => $run->metadata['requirements']['missing'],
        ]);

        return $run->load(['template.priceBucket', 'connection']);
    }

    public function previewBulk(array $templates, User $actor): array
    {
        return collect($templates)
            ->map(fn (PrebidGamTemplate $template): PrebidSetupRun => $this->preview($template, $actor))
            ->all();
    }

    public function execute(PrebidSetupRun $run, User $actor, bool $administratorConfirmed, int $batchSize = 100): PrebidSetupRun
    {
        if (! $administratorConfirmed) {
            throw ValidationException::withMessages([
                'confirmation' => 'Administrator confirmation is required before writing to Google Ad Manager.',
            ]);
        }

        $run->loadMissing(['template.priceBucket', 'connection']);
        $template = $run->template;
        $connection = $run->connection;
        $plan = $run->plan ?: $this->plan($template);
        $requirements = $this->requirements($connection);
        if ($requirements['missing'] !== []) {
            throw ValidationException::withMessages([
                'gam_connection_id' => 'Incomplete GAM setup: '.implode(', ', $requirements['missing']).'.',
            ]);
        }

        $steps = $plan['steps'] ?? [];
        $offset = max(0, (int) data_get($run->cursor, 'offset', 0));
        $batchSize = max(1, min(500, $batchSize));
        $end = min(count($steps), $offset + $batchSize);
        $counters = array_replace([
            'total' => count($steps), 'existing' => 0, 'remaining' => count($steps),
            'created' => 0, 'skipped' => 0, 'failed' => 0, 'processed' => 0, 'by_type' => [],
        ], $run->counters ?? []);

        $run->update([
            'status' => 'RUNNING',
            'dry_run' => false,
            'confirmed' => true,
            'started_at' => $run->started_at ?: now(),
            'plan' => $plan,
            'metadata' => array_replace($run->metadata ?? [], ['requirements' => $requirements]),
        ]);

        $connector = $this->connectors->for($connection);
        for ($index = $offset; $index < $end; $index++) {
            $step = $steps[$index];
            try {
                $created = $this->performStep($connector, $run, $template, $step);
                $counters[$created ? 'created' : 'skipped']++;
                $counters['processed']++;
                $type = $step['type'];
                $counters['by_type'][$type] = (int) ($counters['by_type'][$type] ?? 0) + 1;
                $run->update([
                    'counters' => $counters,
                    'cursor' => ['offset' => $index + 1, 'last_key' => $step['key']],
                ]);
            } catch (Throwable $exception) {
                $counters['failed']++;
                $run->update([
                    'status' => 'FAILED',
                    'counters' => $counters,
                    'cursor' => ['offset' => $index, 'last_key' => $step['key']],
                    'completed_at' => now(),
                ]);
                PrebidError::withoutGlobalScopes()->create([
                    'organization_id' => $run->organization_id,
                    'gam_connection_id' => $run->gam_connection_id,
                    'prebid_setup_run_id' => $run->id,
                    'category' => 'GAM_SETUP',
                    'code' => 'STEP_FAILED',
                    'message' => mb_substr($exception->getMessage(), 0, 10000),
                    'retryable' => true,
                    'context' => ['step' => $step['type'], 'object_key' => $step['key'], 'offset' => $index],
                    'occurred_at' => now(),
                ]);
                $this->audit->record('prebid.gam_setup.failed', $run->organization_id, $actor, $run, newValues: [
                    'step' => $step['type'], 'object_key' => $step['key'], 'offset' => $index,
                ]);

                return $run->refresh()->load(['template.priceBucket', 'connection', 'errors']);
            }
        }

        $complete = $end >= count($steps);
        $counters['remaining'] = max(0, count($steps) - $end);
        $run->update([
            'status' => $complete ? 'SUCCEEDED' : 'PARTIAL',
            'counters' => $counters,
            'cursor' => ['offset' => $end, 'last_key' => $steps[$end - 1]['key'] ?? null],
            'completed_at' => $complete ? now() : null,
            'metadata' => array_replace($run->metadata ?? [], ['incomplete' => $this->incomplete($template, $plan)]),
        ]);

        $this->audit->record($complete ? 'prebid.gam_setup.completed' : 'prebid.gam_setup.batch_completed', $run->organization_id, $actor, $run, newValues: [
            'status' => $run->status,
            'processed' => $counters['processed'],
            'created' => $counters['created'],
            'skipped' => $counters['skipped'],
            'remaining' => $counters['remaining'],
        ]);

        return $run->refresh()->load(['template.priceBucket', 'connection']);
    }

    public function executeBulk(array $runs, User $actor, bool $administratorConfirmed, int $batchSize = 100): array
    {
        if (! $administratorConfirmed) {
            throw ValidationException::withMessages([
                'confirmation' => 'Administrator confirmation is required before writing to Google Ad Manager.',
            ]);
        }

        return collect($runs)
            ->map(fn (PrebidSetupRun $run): PrebidSetupRun => $this->execute($run, $actor, true, $batchSize))
            ->all();
    }

    public function resume(PrebidSetupRun $run, User $actor, int $batchSize = 100): PrebidSetupRun
    {
        return $this->execute($run, $actor, true, $batchSize);
    }

    public function incomplete(PrebidGamTemplate $template, ?array $plan = null): array
    {
        $template->loadMissing(['connection', 'priceBucket']);
        $plan ??= $this->plan($template);
        $existing = $this->existingKeys($template->connection, $plan['steps']);
        $missing = collect($plan['steps'])
            ->reject(fn (array $step): bool => isset($existing[$this->stepIdentity($step)]))
            ->map(fn (array $step): array => ['type' => $step['type'], 'key' => $step['key']])
            ->values()
            ->all();

        return [
            'complete' => $missing === [],
            'expected' => count($plan['steps']),
            'mapped' => count($plan['steps']) - count($missing),
            'missing_count' => count($missing),
            'missing' => array_slice($missing, 0, 100),
        ];
    }

    private function plan(PrebidGamTemplate $template): array
    {
        $prices = $this->priceBuckets->values($template->priceBucket);
        $maxPerOrder = max(1, min(450, (int) $template->max_line_items_per_order));
        $steps = [['type' => 'advertiser', 'key' => 'prebid-advertiser']];

        foreach ($template->targeting_keys as $name => $definition) {
            $steps[] = ['type' => 'targeting_key', 'key' => (string) $name, 'definition' => $definition];
        }
        foreach ($prices as $price) {
            $steps[] = ['type' => 'targeting_value', 'key' => 'hb_pb:'.$price, 'targeting_key' => 'hb_pb', 'value' => $price];
        }
        foreach ((array) $template->targeting_values as $key => $values) {
            foreach ((array) $values as $value) {
                $steps[] = ['type' => 'targeting_value', 'key' => $key.':'.$value, 'targeting_key' => $key, 'value' => (string) $value];
            }
        }

        $orderCount = max(1, (int) ceil(count($prices) / $maxPerOrder));
        for ($order = 0; $order < $orderCount; $order++) {
            $steps[] = ['type' => 'order', 'key' => 'order:'.($order + 1), 'order_index' => $order];
        }
        foreach ($template->creative_sizes as $size) {
            $steps[] = ['type' => 'creative', 'key' => 'creative:'.$size[0].'x'.$size[1], 'size' => [(int) $size[0], (int) $size[1]]];
        }
        foreach ($prices as $index => $price) {
            $steps[] = ['type' => 'line_item', 'key' => 'line-item:'.$price, 'price' => $price, 'order_index' => intdiv($index, $maxPerOrder)];
            foreach ($template->creative_sizes as $size) {
                $steps[] = [
                    'type' => 'association',
                    'key' => 'association:'.$price.':'.$size[0].'x'.$size[1],
                    'price' => $price,
                    'creative_key' => 'creative:'.$size[0].'x'.$size[1],
                    'size' => [(int) $size[0], (int) $size[1]],
                ];
            }
        }

        return [
            'version' => 1,
            'prices' => $prices,
            'creative_sizes' => $template->creative_sizes,
            'max_line_items_per_order' => $maxPerOrder,
            'steps' => $steps,
            'counts' => collect($steps)->countBy('type')->all(),
        ];
    }

    private function performStep($connector, PrebidSetupRun $run, PrebidGamTemplate $template, array $step): bool
    {
        $connection = $run->connection;
        $configuration = $connection->configuration ?? [];
        $advertiser = fn (): PrebidGamRemoteObject => $this->requireRemote($connection, 'advertiser', 'prebid-advertiser');

        return match ($step['type']) {
            'advertiser' => $this->ensureObject($run, 'advertiser', $step['key'], [
                'name' => $template->advertiser_name,
                'type' => 'ADVERTISER',
            ], fn (array $payload, array $options): GamResult => $connector->createCompany($payload, $options)),
            'targeting_key' => $this->ensureObject($run, 'targeting_key', $step['key'], [
                'name' => $step['key'],
                'displayName' => $step['definition']['displayName'] ?? $step['key'],
                'type' => $step['definition']['type'] ?? 'FREEFORM',
            ], fn (array $payload, array $options): GamResult => $connector->createCustomTargetingKey($payload, $options)),
            'targeting_value' => $this->ensureObject($run, 'targeting_value', $step['key'], [
                'customTargetingKeyId' => $this->requireRemote($connection, 'targeting_key', $step['targeting_key'])->remote_object_id,
                'name' => $step['value'],
                'displayName' => $step['value'],
                'matchType' => 'EXACT',
            ], fn (array $payload, array $options): GamResult => $connector->createCustomTargetingValue($payload, $options)),
            'order' => $this->ensureObject($run, 'order', $step['key'], [
                'name' => $template->order_prefix.' '.($step['order_index'] + 1),
                'advertiserId' => $advertiser()->remote_object_id,
                'traffickerId' => (string) $configuration['trafficker_id'],
            ], fn (array $payload, array $options): GamResult => $connector->createOrder($payload, $options)),
            'creative' => $this->ensureObject($run, 'creative', $step['key'], [
                '__type' => 'ThirdPartyCreative',
                'advertiserId' => $advertiser()->remote_object_id,
                'name' => $template->creative_prefix.' '.$step['size'][0].'x'.$step['size'][1],
                'size' => ['width' => $step['size'][0], 'height' => $step['size'][1], 'isAspectRatio' => false],
                'snippet' => $template->universal_creative_snippet,
                'isSafeFrameCompatible' => true,
            ], fn (array $payload, array $options): GamResult => $connector->createCreative($payload, $options)),
            'line_item' => $this->ensureObject($run, 'line_item', $step['key'], $this->lineItemPayload($run, $template, $step), fn (array $payload, array $options): GamResult => $connector->createLineItem($payload, $options)),
            'association' => $this->ensureObject($run, 'association', $step['key'], [
                'lineItemId' => $this->requireRemote($connection, 'line_item', 'line-item:'.$step['price'])->remote_object_id,
                'creativeId' => $this->requireRemote($connection, 'creative', $step['creative_key'])->remote_object_id,
                'sizes' => [['width' => $step['size'][0], 'height' => $step['size'][1], 'isAspectRatio' => false]],
            ], fn (array $payload, array $options): GamResult => $connector->associateCreative($payload, $options)),
            default => throw new RuntimeException('Unknown Prebid GAM setup step '.$step['type'].'.'),
        };
    }

    private function lineItemPayload(PrebidSetupRun $run, PrebidGamTemplate $template, array $step): array
    {
        $connection = $run->connection;
        $configuration = $connection->configuration ?? [];
        $price = (float) $step['price'];
        $priceValue = $this->requireRemote($connection, 'targeting_value', 'hb_pb:'.$step['price']);
        $priceKey = $this->requireRemote($connection, 'targeting_key', 'hb_pb');
        $order = $this->requireRemote($connection, 'order', 'order:'.($step['order_index'] + 1));

        return [
            'name' => $template->line_item_prefix.' '.$step['price'],
            'orderId' => $order->remote_object_id,
            'lineItemType' => 'PRICE_PRIORITY',
            'priority' => (int) $template->priority,
            'startDateTimeType' => 'IMMEDIATELY',
            'unlimitedEndDateTime' => true,
            'costType' => 'CPM',
            'costPerUnit' => [
                'currencyCode' => strtoupper($template->currency_code),
                'microAmount' => (int) round($price * 1_000_000),
            ],
            'creativeRotationType' => 'EVEN',
            'primaryGoal' => ['goalType' => 'NONE', 'unitType' => 'IMPRESSIONS', 'units' => 0],
            'creativePlaceholders' => collect($template->creative_sizes)->map(fn (array $size): array => [
                'size' => ['width' => (int) $size[0], 'height' => (int) $size[1], 'isAspectRatio' => false],
            ])->values()->all(),
            'targeting' => [
                'inventoryTargeting' => [
                    'targetedAdUnits' => [[
                        'adUnitId' => (string) $configuration['root_ad_unit_id'],
                        'includeDescendants' => true,
                    ]],
                ],
                'customTargeting' => [
                    '__type' => 'CustomCriteriaSet',
                    'logicalOperator' => 'AND',
                    'children' => [[
                        '__type' => 'CustomCriteria',
                        'keyId' => $priceKey->remote_object_id,
                        'operator' => 'IS',
                        'valueIds' => [$priceValue->remote_object_id],
                    ]],
                ],
            ],
        ];
    }

    private function ensureObject(PrebidSetupRun $run, string $type, string $key, array $payload, callable $operation): bool
    {
        $existing = PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $run->gam_connection_id)
            ->where('object_type', $type)
            ->where('object_key', $key)
            ->first();
        if ($existing) {
            return false;
        }

        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $result = $operation($payload, [
            'dry_run' => false,
            'idempotency_key' => hash('sha256', 'prebid|'.$run->gam_connection_id.'|'.$type.'|'.$key.'|'.$payloadHash),
        ]);
        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?: "GAM failed to create {$type} {$key}.");
        }

        $remoteId = $this->extractRemoteId($result->data);
        if ($remoteId === null) {
            throw new RuntimeException("GAM created {$type} {$key} without returning a remote ID.");
        }

        PrebidGamRemoteObject::withoutGlobalScopes()->create([
            'organization_id' => $run->organization_id,
            'gam_connection_id' => $run->gam_connection_id,
            'prebid_setup_run_id' => $run->id,
            'object_key' => $key,
            'object_type' => $type,
            'remote_object_id' => $remoteId,
            'payload_hash' => $payloadHash,
            'remote_status' => data_get($result->data, 'status'),
            'metadata' => ['operation_id' => $result->operationId, 'duplicate_operation' => $result->duplicate],
            'synced_at' => now(),
        ]);

        return true;
    }

    private function requireRemote(GamConnection $connection, string $type, string $key): PrebidGamRemoteObject
    {
        return PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('object_type', $type)
            ->where('object_key', $key)
            ->first()
            ?? throw new RuntimeException("Required GAM {$type} {$key} is missing. Resume the setup from its last successful step.");
    }

    private function extractRemoteId(array $data): ?string
    {
        foreach (['id', 'companyId', 'orderId', 'lineItemId', 'creativeId', 'customTargetingKeyId', 'customTargetingValueId'] as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $id = $this->extractRemoteId($value);
                if ($id !== null) {
                    return $id;
                }
            }
        }

        return null;
    }

    private function existingKeys(GamConnection $connection, array $steps): array
    {
        $identities = collect($steps)->map(fn (array $step): string => $this->stepIdentity($step));

        return PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->get()
            ->mapWithKeys(fn (PrebidGamRemoteObject $object): array => [$object->object_type.'|'.$object->object_key => true])
            ->only($identities->all())
            ->all();
    }

    private function stepIdentity(array $step): string
    {
        return $step['type'].'|'.$step['key'];
    }

    private function requirements(GamConnection $connection): array
    {
        $required = [
            'network_code' => $connection->network_code,
            'configuration.root_ad_unit_id' => data_get($connection->configuration, 'root_ad_unit_id'),
            'configuration.trafficker_id' => data_get($connection->configuration, 'trafficker_id'),
        ];

        return [
            'values' => $required,
            'missing' => array_keys(array_filter($required, fn ($value): bool => blank($value))),
        ];
    }

    private function universalCreativeSnippet(): string
    {
        $url = htmlspecialchars((string) config('prebid.universal_creative_url'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<script src="{$url}"></script>
<script>
var ucTagData = {};
ucTagData.adServerDomain = "";
ucTagData.pubUrl = "%%PATTERN:url%%";
ucTagData.targetingMap = %%PATTERN:TARGETINGMAP%%;
ucTagData.hbPb = "%%PATTERN:hb_pb%%";
ucTagData.requestAllAssets = true;
ucTagData.clickUrlUnesc = "%%CLICK_URL_UNESC%%";
try { ucTag.renderAd(document, ucTagData); } catch (e) { console.error(e); }
</script>
HTML;
    }
}
