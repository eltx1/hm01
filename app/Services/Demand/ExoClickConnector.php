<?php

namespace App\Services\Demand;

use App\Models\DemandPlacement;
use RuntimeException;

final class ExoClickConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'EXOCLICK';
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $tag = parent::generateDirectTag($placement);
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $zoneId = trim((string) (
            data_get($tag, 'container.attributes.data-zoneid')
            ?: ($configuration['zone_id'] ?? null)
            ?: $widget?->remote_widget_id
            ?: $placement->remote_placement_id
            ?: ''
        ));
        if ($zoneId === '') {
            throw new RuntimeException('ExoClick Direct JS requires the provider-issued public zone ID.');
        }

        $providerClass = trim((string) ($configuration['container_class'] ?? data_get($tag, 'container.class')));
        if ($providerClass === '' || $providerClass === 'hm-direct-demand-container') {
            throw new RuntimeException('ExoClick Direct JS requires the provider-issued container class from the approved asynchronous tag.');
        }

        $tag['container']['element'] = 'ins';
        $tag['container']['class'] = preg_replace('/[^A-Za-z0-9_\- ]/', '-', $providerClass);
        $tag['container']['attributes'] = ['data-zoneid' => $zoneId] + (array) ($tag['container']['attributes'] ?? []);
        $tag['publicPlacementId'] = $zoneId;
        $tag['initialization'] = ['type' => 'EXOCLICK_SERVE', 'parameters' => []];
        $tag['containerClass'] = $tag['container']['class'];
        $tag['attributes'] = $tag['container']['attributes'];

        return $tag;
    }

    protected function trustedInitializationForParsedTag(array $parsed): array
    {
        $container = (array) data_get($parsed, 'detectedContainers.0', []);
        if (strtolower((string) ($container['element'] ?? '')) !== 'ins'
            || trim((string) data_get($container, 'attributes.data-zoneid')) === '') {
            throw new RuntimeException('ExoClick asynchronous banner tags require an <ins> container with provider-issued data-zoneid.');
        }

        $inline = (array) ($parsed['inlineCode'] ?? []);
        if ($inline === []) {
            throw new RuntimeException('ExoClick asynchronous tags require the documented AdProvider serve queue action.');
        }

        foreach ($inline as $code) {
            $normalized = preg_replace('/\s+/', '', (string) $code) ?: '';
            $safe = preg_match('/^\(?AdProvider=window\.AdProvider\|\|\[\]\)?\.push\(\{["\']?serve["\']?:\{\}\}\);?$/i', $normalized);
            if (! $safe) {
                throw new RuntimeException('ExoClick inline code is not the documented AdProvider serve queue recipe.');
            }
        }

        return ['type' => 'EXOCLICK_SERVE', 'parameters' => []];
    }

    protected function allowedInitializationTypes(): array
    {
        return ['NONE', 'EXOCLICK_SERVE'];
    }
}
