<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignCreativeType;
use App\Enums\CampaignNetworkStatus;
use App\Enums\CampaignPricingModel;
use App\Models\Campaign;
use App\Models\CampaignCreative;
use App\Models\CampaignNetworkInstance;
use App\Models\GamRemoteObject;
use App\Models\Placement;
use App\Services\Gam\GamConnectionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CampaignNetworkPlanner
{
    public function __construct(private readonly GamConnectionResolver $connections)
    {
    }

    public function rebuildInstances(Campaign $campaign): Collection
    {
        $campaign->loadMissing(['sites.site', 'placements.placement', 'budget']);
        $groups = [];
        foreach ($campaign->sites->where('is_active', true) as $campaignSite) {
            $site = $campaignSite->site;
            $connection = $site ? $this->connections->resolve($site) : null;
            if (! $connection) {
                throw ValidationException::withMessages(['site_ids' => 'No enabled GAM connection can be resolved for '.($site?->display_name ?? $campaignSite->site_id).'.']);
            }
            $groups[$connection->id]['connection'] = $connection;
            $groups[$connection->id]['sites'][] = $campaignSite;
        }
        if ($groups === []) throw ValidationException::withMessages(['site_ids' => 'The campaign has no active websites.']);

        $totalWeight = collect($groups)->sum(fn (array $group) => collect($group['sites'])->sum(fn ($row) => (float) $row->budget_weight));
        $remaining = (int) $campaign->total_budget_minor;
        $instances = collect();
        $index = 0;
        foreach ($groups as $connectionId => $group) {
            $index++;
            $weight = collect($group['sites'])->sum(fn ($row) => (float) $row->budget_weight);
            $allocation = $index === count($groups) ? $remaining : (int) floor($campaign->total_budget_minor * ($weight / max($totalWeight, 0.0001)));
            $remaining -= $allocation;
            $siteIds = collect($group['sites'])->pluck('site_id')->values()->all();
            $placementIds = $campaign->placements->where('is_active', true)
                ->filter(fn ($row) => in_array($row->placement?->site_id, $siteIds, true))
                ->pluck('placement_id')->values()->all();
            $connection = $group['connection'];

            $instance = CampaignNetworkInstance::withoutGlobalScopes()
                ->where('campaign_id', $campaign->id)
                ->where('gam_connection_id', $connectionId)
                ->first();
            $routingChanged = ! $instance
                || collect($instance->site_ids ?? [])->sort()->values()->all() !== collect($siteIds)->sort()->values()->all()
                || collect($instance->placement_ids ?? [])->sort()->values()->all() !== collect($placementIds)->sort()->values()->all()
                || (int) $instance->budget_allocated_minor !== $allocation
                || $instance->network_code !== $connection->network_code
                || $instance->network_type !== $connection->type;
            $attributes = [
                'organization_id' => $campaign->organization_id,
                'network_type' => $connection->type,
                'network_code' => $connection->network_code,
                'budget_allocated_minor' => $allocation,
                'site_ids' => $siteIds,
                'placement_ids' => $placementIds,
            ];
            if ($routingChanged) {
                $attributes = array_merge($attributes, [
                    'status' => CampaignNetworkStatus::Pending,
                    'deployment_plan' => null,
                    'planned_objects' => 0,
                    'completed_objects' => 0,
                    'cursor' => 0,
                    'last_error' => null,
                ]);
            }
            if ($instance) $instance->update($attributes);
            else $instance = CampaignNetworkInstance::withoutGlobalScopes()->create(array_merge($attributes, [
                'campaign_id' => $campaign->id,
                'gam_connection_id' => $connectionId,
                'status' => CampaignNetworkStatus::Pending,
            ]));
            $instances->push($instance->fresh());
        }

        CampaignNetworkInstance::withoutGlobalScopes()
            ->where('campaign_id', $campaign->id)
            ->whereNotIn('gam_connection_id', array_keys($groups))
            ->get()
            ->each(fn (CampaignNetworkInstance $stale) => $stale->update([
                'status' => CampaignNetworkStatus::Completed,
                'budget_allocated_minor' => 0,
                'site_ids' => [],
                'placement_ids' => [],
            ]));

        return $instances;
    }

    public function preview(Campaign $campaign): array
    {
        $instances = $this->rebuildInstances($campaign);
        $plans = $instances->map(fn (CampaignNetworkInstance $instance) => $this->plan($instance))->values()->all();

        return [
            'campaignId' => $campaign->id,
            'networkInstances' => count($plans),
            'estimatedObjects' => collect($plans)->sum('estimatedObjects'),
            'pendingObjects' => collect($plans)->sum('pendingObjects'),
            'issues' => collect($plans)->flatMap(fn (array $plan) => $plan['issues'])->values()->all(),
            'plans' => $plans,
        ];
    }

    public function plan(CampaignNetworkInstance $instance): array
    {
        $instance->loadMissing(['campaign.advertiser', 'campaign.budget', 'campaign.targets', 'campaign.creatives.files', 'connection']);
        $campaign = $instance->campaign;
        $connection = $instance->connection;
        $issues = [];

        $placements = $this->selectedPlacements($instance);
        $adUnitIds = $placements->pluck('ad_unit_id')->filter()->unique()->values();
        if ($adUnitIds->isEmpty()) $issues[] = 'No active placements or ad units are available for this network instance.';
        $remoteAdUnits = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('local_object_type', 'ad_unit')
            ->where('remote_object_type', 'ad_unit')
            ->whereIn('local_object_id', $adUnitIds)
            ->pluck('remote_object_id', 'local_object_id');
        $missingAdUnits = $adUnitIds->diff($remoteAdUnits->keys());
        if ($missingAdUnits->isNotEmpty()) $issues[] = 'Synchronize '.count($missingAdUnits).' selected ad unit(s) to '.$connection->name.' before deployment.';

        $creatives = $campaign->creatives
            ->filter(fn (CampaignCreative $creative) => $creative->is_active && $creative->status->value === 'APPROVED')
            ->values();
        if ($creatives->isEmpty()) $issues[] = 'At least one approved active creative is required.';

        $objects = [];
        $objects[] = $this->object('advertiser', $campaign->advertiser_id, 'company', 'createCompany', 'updateCompany', [
            'name' => $campaign->advertiser->display_name,
            'type' => 'ADVERTISER',
            'externalId' => $campaign->advertiser->public_key ?: $campaign->advertiser->id,
        ], 'company');
        $objects[] = $this->object('campaign', $campaign->id, 'order', 'createOrder', 'updateOrder', [
            'name' => 'Horus · '.$campaign->name.' · '.$instance->network_type->value,
            'advertiserId' => '@company',
            'status' => 'DRAFT',
            'notes' => 'Managed by Horus Media campaign '.$campaign->public_key,
        ], 'order');

        $lineItem = $this->lineItemPayload($instance, $remoteAdUnits->values()->all(), $placements, $issues);
        $objects[] = $this->object('campaign_network_instance', $instance->id, 'line_item', 'createLineItem', 'updateLineItem', $lineItem, 'line_item');

        foreach ($creatives as $creative) {
            $payload = $this->creativePayload($creative, $connection->configuration ?? [], $issues);
            $objects[] = $this->object('campaign_creative', $creative->id, 'creative', 'createCreative', null, $payload, 'creative:'.$creative->id);
            $associationId = $instance->id.':'.$creative->id;
            $objects[] = $this->object('campaign_creative_association', $associationId, 'creative_association', 'associateCreative', null, [
                'lineItemId' => '@line_item',
                'creativeId' => '@creative:'.$creative->id,
            ], 'association:'.$creative->id);
        }

        $existing = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->whereIn('local_object_id', collect($objects)->pluck('localId')->unique())
            ->get()
            ->keyBy(fn (GamRemoteObject $mapping) => $mapping->local_object_type.'|'.$mapping->local_object_id.'|'.$mapping->remote_object_type);
        $pending = collect($objects)->filter(function (array $object) use ($existing): bool {
            $key = $object['localType'].'|'.$object['localId'].'|'.$object['remoteType'];
            $mapping = $existing->get($key);
            return ! $mapping || $mapping->payload_hash !== $object['payloadHash'];
        })->count();

        $plan = [
            'instanceId' => $instance->id,
            'connectionId' => $connection->id,
            'connectionName' => $connection->name,
            'networkType' => $instance->network_type->value,
            'networkCode' => $connection->network_code,
            'budgetAllocatedMinor' => (int) $instance->budget_allocated_minor,
            'siteIds' => $instance->site_ids,
            'placementIds' => $placements->pluck('id')->values()->all(),
            'estimatedObjects' => count($objects),
            'existingObjects' => count($objects) - $pending,
            'pendingObjects' => $pending,
            'issues' => array_values(array_unique($issues)),
            'objects' => $objects,
        ];
        $instance->update(['deployment_plan' => $plan, 'planned_objects' => count($objects)]);

        return $plan;
    }

    private function selectedPlacements(CampaignNetworkInstance $instance): Collection
    {
        $query = Placement::withoutGlobalScopes()->with(['adUnit.sizes', 'sizes'])
            ->whereIn('site_id', $instance->site_ids ?? [])
            ->where('status', 'ACTIVE');
        $selected = $instance->placement_ids ?? [];
        if ($selected !== []) $query->whereIn('id', $selected);
        return $query->get()->filter(fn (Placement $placement) => $placement->adUnit?->is_enabled)->values();
    }

    private function lineItemPayload(CampaignNetworkInstance $instance, array $remoteAdUnitIds, Collection $placements, array &$issues): array
    {
        $campaign = $instance->campaign;
        $budget = $campaign->budget;
        $countries = collect($campaign->targets->firstWhere('dimension', 'COUNTRY')?->values ?? []);
        $devices = collect($campaign->targets->firstWhere('dimension', 'DEVICE')?->values ?? []);
        $countryMap = collect(data_get($instance->connection->configuration, 'country_location_ids', []));
        $deviceMap = collect(data_get($instance->connection->configuration, 'device_category_ids', []));
        $countryIds = $countries->map(fn ($code) => $countryMap->get($code))->filter()->values();
        $deviceIds = $devices->map(fn ($code) => $deviceMap->get($code))->filter()->values();
        if ($countries->count() !== $countryIds->count()) $issues[] = 'Some target countries have no GAM location ID mapping on this connection.';
        if ($devices->count() !== $deviceIds->count()) $issues[] = 'Some target devices have no GAM device-category ID mapping on this connection.';

        $targeting = [
            'inventoryTargeting' => ['targetedAdUnits' => collect($remoteAdUnitIds)->map(fn ($id) => ['adUnitId' => (string) $id, 'includeDescendants' => false])->values()->all()],
        ];
        if ($countryIds->isNotEmpty()) $targeting['geoTargeting'] = ['targetedLocations' => $countryIds->map(fn ($id) => ['id' => (string) $id])->all()];
        if ($deviceIds->isNotEmpty()) $targeting['technologyTargeting'] = ['deviceCategoryTargeting' => ['targetedDeviceCategories' => $deviceIds->map(fn ($id) => ['id' => (string) $id])->all()]];

        $sizes = $placements->flatMap(function (Placement $placement): array {
            return $placement->sizes->filter(fn ($size) => $size->size_type === 'FIXED' && $size->width && $size->height)
                ->map(fn ($size) => [(int) $size->width, (int) $size->height])->all();
        })->unique(fn ($size) => implode('x', $size))->values();
        if ($sizes->isEmpty()) $sizes = collect([[1, 1]]);

        $goalUnits = match ($campaign->pricing_model) {
            CampaignPricingModel::Cpc => (int) ($campaign->goals->firstWhere('goal_type', 'CLICKS')?->target_value ?? 1),
            default => (int) ($campaign->goals->firstWhere('goal_type', 'IMPRESSIONS')?->target_value ?? 1),
        };
        $pricing = $this->pricing($campaign->pricing_model, (int) ($budget?->unit_price_minor ?? 0), max(1, $goalUnits));
        $payload = [
            'name' => 'Horus · '.$campaign->name.' · '.$instance->network_type->value,
            'orderId' => '@order',
            'lineItemType' => $pricing['lineItemType'],
            'priority' => $pricing['priority'],
            'costType' => $pricing['costType'],
            'costPerUnit' => ['currencyCode' => $campaign->currency, 'microAmount' => $pricing['microAmount']],
            'primaryGoal' => $pricing['goal'],
            'creativePlaceholders' => $sizes->map(fn ($size) => ['size' => ['width' => $size[0], 'height' => $size[1], 'isAspectRatio' => false]])->all(),
            'targeting' => $targeting,
            'startDateTime' => $campaign->starts_at->utc()->toIso8601String(),
            'endDateTime' => $campaign->ends_at->utc()->toIso8601String(),
            'unlimitedEndDateTime' => false,
        ];
        if ($campaign->frequency_cap_impressions && $campaign->frequency_cap_days) {
            $payload['frequencyCaps'] = [[
                'maxImpressions' => (int) $campaign->frequency_cap_impressions,
                'numTimeUnits' => (int) $campaign->frequency_cap_days,
                'timeUnit' => 'DAY',
            ]];
        }
        return $payload;
    }

    private function pricing(CampaignPricingModel $model, int $unitPriceMinor, int $goalUnits): array
    {
        $lineItemType = match ($model) {
            CampaignPricingModel::FixedSponsorship => 'SPONSORSHIP',
            CampaignPricingModel::House => 'HOUSE',
            CampaignPricingModel::Bonus => 'STANDARD',
            default => 'STANDARD',
        };
        $costType = match ($model) {
            CampaignPricingModel::Cpc => 'CPC',
            CampaignPricingModel::FixedSponsorship => 'CPD',
            default => 'CPM',
        };
        $unitType = match ($model) {
            CampaignPricingModel::Cpc => 'CLICKS',
            CampaignPricingModel::Cpv => 'VIEWED_IMPRESSIONS',
            default => 'IMPRESSIONS',
        };
        return [
            'lineItemType' => $lineItemType,
            'priority' => match ($model) { CampaignPricingModel::FixedSponsorship => 4, CampaignPricingModel::House => 16, CampaignPricingModel::Bonus => 12, default => 8 },
            'costType' => $costType,
            'microAmount' => $unitPriceMinor * 10_000,
            'goal' => ['goalType' => 'LIFETIME', 'unitType' => $unitType, 'units' => $goalUnits],
        ];
    }

    private function creativePayload(CampaignCreative $creative, array $connectionConfig, array &$issues): array
    {
        $base = [
            'name' => $creative->name,
            'advertiserId' => '@company',
            'size' => ['width' => $creative->width ?? 1, 'height' => $creative->height ?? 1, 'isAspectRatio' => false],
        ];
        $destinationUrl = $creative->click_through_url ?: $creative->landing_url;
        $file = $creative->files->first();
        return match ($creative->type) {
            CampaignCreativeType::Image => $base + ['__type' => 'ImageCreative', 'destinationUrl' => $destinationUrl, '_file' => $file?->only(['disk', 'path', 'mime_type', 'original_name'])],
            CampaignCreativeType::Html5 => $base + ['__type' => 'Html5Creative', '_file' => $file?->only(['disk', 'path', 'mime_type', 'original_name'])],
            CampaignCreativeType::ThirdPartyTag => $base + ['__type' => 'ThirdPartyCreative', 'snippet' => $creative->html_content, 'isSafeFrameCompatible' => true],
            CampaignCreativeType::Native => $base + ['destinationUrl' => $destinationUrl] + $this->nativePayload($creative, $connectionConfig, $issues),
            CampaignCreativeType::VideoVast => $base + ['__type' => 'VastRedirectCreative', 'vastXmlUrl' => $creative->vast_url],
            CampaignCreativeType::Text => $base + ['__type' => 'ThirdPartyCreative', 'snippet' => '<a rel="sponsored noopener" target="_blank" href="'.e($creative->click_through_url ?: $creative->landing_url).'">'.e($creative->text_content).'</a>', 'isSafeFrameCompatible' => true],
            CampaignCreativeType::House => $file
                ? $base + ['__type' => str_ends_with(strtolower((string) $file->original_name), '.zip') ? 'Html5Creative' : 'ImageCreative']
                    + (str_ends_with(strtolower((string) $file->original_name), '.zip') ? [] : ['destinationUrl' => $destinationUrl])
                    + ['_file' => $file->only(['disk', 'path', 'mime_type', 'original_name'])]
                : $base + ['__type' => 'ThirdPartyCreative', 'snippet' => $creative->html_content ?: '<span>'.e($creative->text_content).'</span>', 'isSafeFrameCompatible' => true],
        };
    }

    private function nativePayload(CampaignCreative $creative, array $config, array &$issues): array
    {
        $templateId = data_get($config, 'native_creative_template_id');
        if (! $templateId) $issues[] = 'Native creatives require configuration.native_creative_template_id on the GAM connection.';
        return [
            '__type' => 'TemplateCreative',
            'creativeTemplateId' => $templateId,
            'creativeTemplateVariableValues' => collect($creative->native_assets ?? [])->map(fn ($value, $key) => [
                '__type' => 'StringCreativeTemplateVariableValue',
                'uniqueName' => (string) $key,
                'value' => is_scalar($value) ? (string) $value : json_encode($value),
            ])->values()->all(),
        ];
    }

    private function object(string $localType, string $localId, string $remoteType, string $createMethod, ?string $updateMethod, array $payload, string $reference): array
    {
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        return [
            'localType' => $localType,
            'localId' => $localId,
            'remoteType' => $remoteType,
            'createMethod' => $createMethod,
            'updateMethod' => $updateMethod,
            'payload' => $payload,
            'payloadHash' => $hash,
            'reference' => $reference,
            'idempotencyKey' => 'campaign:'.hash('sha256', $localType.'|'.$localId.'|'.$remoteType.'|'.$reference),
        ];
    }
}
