<?php

namespace App\Services\Prebid;

use App\Enums\ServingMode;
use App\Models\GamConnection;
use App\Models\GamRemoteObject;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidGamTemplate;
use App\Models\PrebidPriceBucket;
use App\Models\PrebidSetting;
use App\Models\Site;
use App\Services\Gam\GamConnectionResolver;
use Illuminate\Support\Collection;

final class PrebidGamPlanBuilder
{
    public function __construct(private readonly GamConnectionResolver $connections)
    {
    }

    public function build(GamConnection $connection, PrebidGamTemplate $template, ?Site $site = null): array
    {
        $sites = $this->sites($connection, $site);
        $placements = $sites->flatMap(fn (Site $candidate) => $candidate->placements)->values();
        $sizes = $placements
            ->flatMap(fn ($placement) => $placement->sizes)
            ->filter(fn ($size) => $size->is_active && $size->size_type === 'FIXED' && $size->width && $size->height)
            ->map(fn ($size) => ['width' => (int) $size->width, 'height' => (int) $size->height])
            ->unique(fn (array $size) => $size['width'].'x'.$size['height'])
            ->sortBy(fn (array $size) => $size['width'] * $size['height'])
            ->values();

        $adUnitIds = $placements->pluck('ad_unit_id')->filter()->unique()->values();
        $adUnitMappings = GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('local_object_type', 'ad_unit')
            ->where('remote_object_type', 'ad_unit')
            ->whereIn('local_object_id', $adUnitIds)
            ->get()
            ->keyBy('local_object_id');

        $missingAdUnits = $adUnitIds->reject(fn (string $id) => $adUnitMappings->has($id))->values()->all();
        $prices = $this->prices($sites);
        $operations = [];

        $operations[] = $this->operation('company', 'company', ['name' => $template->advertiser_name]);
        foreach ($template->targeting_keys as $key) {
            $operations[] = $this->operation('targeting-key:'.$key, 'targeting_key', ['name' => $key]);
        }
        foreach ($prices as $price) {
            $operations[] = $this->operation('targeting-value:hb_pb:'.$price, 'targeting_value', ['key' => 'hb_pb', 'value' => $price]);
        }
        $operations[] = $this->operation('order', 'order', [
            'name' => $template->order_name_prefix.' '.$connection->network_code,
        ]);
        foreach ($sizes as $size) {
            $sizeKey = $size['width'].'x'.$size['height'];
            $operations[] = $this->operation('creative:'.$sizeKey, 'creative', ['size' => $size]);
        }
        foreach ($prices as $price) {
            $operations[] = $this->operation('line-item:'.$price, 'line_item', [
                'price' => $price,
                'sizes' => $sizes->all(),
                'ad_unit_remote_ids' => $adUnitMappings->pluck('remote_object_id')->values()->all(),
            ]);
            foreach ($sizes as $size) {
                $sizeKey = $size['width'].'x'.$size['height'];
                $operations[] = $this->operation('association:'.$price.':'.$sizeKey, 'association', [
                    'line_item_key' => 'line-item:'.$price,
                    'creative_key' => 'creative:'.$sizeKey,
                ]);
            }
        }

        $existing = PrebidGamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $connection->id)
            ->where('prebid_gam_template_id', $template->id)
            ->pluck('payload_hash', 'object_key');

        $pending = collect($operations)->filter(function (array $operation) use ($existing): bool {
            return ! $existing->has($operation['key']) || $existing->get($operation['key']) !== $operation['payloadHash'];
        })->values()->all();

        $missing = [];
        if (! $connection->network_code) {
            $missing[] = 'The selected GAM connection has no network code.';
        }
        if (! data_get($template->settings, 'trafficker_id')) {
            $missing[] = 'The GAM template requires settings.trafficker_id.';
        }
        if ($sites->isEmpty()) {
            $missing[] = 'No Prebid-enabled websites resolve to this GAM connection.';
        }
        if ($sizes->isEmpty()) {
            $missing[] = 'No active fixed placement sizes are available.';
        }
        if ($adUnitMappings->isEmpty()) {
            $missing[] = 'No synchronized GAM ad units are available for the selected websites.';
        }
        if ($missingAdUnits !== []) {
            $missing[] = count($missingAdUnits).' local ad unit(s) must be synchronized before the Prebid setup is complete.';
        }

        return [
            'connection' => [
                'type' => $connection->type->value,
                'networkCode' => $connection->network_code,
                'name' => $connection->name,
            ],
            'template' => [
                'name' => $template->name,
                'version' => (int) $template->version,
                'mode' => $template->mode,
                'currency' => $template->currency,
            ],
            'siteKeys' => $sites->pluck('public_key')->values()->all(),
            'pricePoints' => $prices,
            'sizes' => $sizes->all(),
            'missingPrerequisites' => $missing,
            'complete' => $missing === [],
            'estimates' => [
                'companies' => 1,
                'targetingKeys' => count($template->targeting_keys),
                'targetingValues' => count($prices),
                'orders' => 1,
                'lineItems' => count($prices),
                'creatives' => $sizes->count(),
                'associations' => count($prices) * $sizes->count(),
                'totalObjects' => count($operations),
                'pendingObjects' => count($pending),
                'existingObjects' => count($operations) - count($pending),
            ],
            'operations' => $operations,
            'pendingOperations' => $pending,
            'generatedAt' => now()->utc()->toIso8601String(),
        ];
    }

    private function sites(GamConnection $connection, ?Site $site): Collection
    {
        $query = $site
            ? collect([$site])
            : Site::withoutGlobalScopes()->where('prebid_enabled', true)->where('serving_mode', '!=', ServingMode::Paused->value)->get();

        return $query
            ->filter(fn (Site $candidate) => $this->connections->resolve($candidate)?->id === $connection->id)
            ->each->loadMissing(['placements.sizes']);
    }

    private function prices(Collection $sites): array
    {
        $customBuckets = PrebidPriceBucket::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->whereIn('prebid_setting_id', PrebidSetting::withoutGlobalScopes()->whereIn('site_id', $sites->pluck('id'))->pluck('id'))
            ->orderBy('priority')
            ->get();

        $definitions = $customBuckets->isNotEmpty()
            ? $customBuckets->map(fn ($bucket) => [
                'minimum' => (float) $bucket->minimum,
                'maximum' => (float) $bucket->maximum,
                'increment' => (float) $bucket->increment,
                'precision' => (int) $bucket->precision,
            ])->all()
            : config('prebid.price_buckets', []);

        $prices = [];
        foreach ($definitions as $definition) {
            $precision = (int) $definition['precision'];
            $factor = 10 ** $precision;
            $min = (int) round(((float) $definition['minimum']) * $factor);
            $max = (int) round(((float) $definition['maximum']) * $factor);
            $step = max(1, (int) round(((float) $definition['increment']) * $factor));
            for ($value = $min; $value <= $max; $value += $step) {
                $prices[number_format($value / $factor, $precision, '.', '')] = true;
                if (count($prices) > 2000) {
                    break 2;
                }
            }
        }

        return array_keys($prices);
    }

    private function operation(string $key, string $type, array $data): array
    {
        $hash = hash('sha256', json_encode(['type' => $type, 'data' => $data], JSON_THROW_ON_ERROR));

        return ['key' => $key, 'type' => $type, 'data' => $data, 'payloadHash' => $hash];
    }
}
