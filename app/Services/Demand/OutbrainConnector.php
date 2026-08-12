<?php

namespace App\Services\Demand;

use App\Models\DemandPlacement;

final class OutbrainConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'OUTBRAIN';
    }

    public function validateConfiguration(array $configuration = []): array
    {
        return array_values(array_unique(array_merge(
            parent::validateConfiguration($configuration),
            $this->requiredAccountIdentifier(),
        )));
    }

    public function generateDirectTag(DemandPlacement $placement): array
    {
        $tag = parent::generateDirectTag($placement);
        $widget = $this->widget($placement);
        $configuration = $this->mergedConfiguration($placement, $widget);
        $widgetId = trim((string) (
            data_get($tag, 'container.attributes.data-widget-id')
            ?: ($configuration['widget_id'] ?? '')
            ?: $widget?->remote_widget_id
            ?: $placement->remote_placement_id
            ?: ''
        ));

        $tag['container']['class'] = 'OUTBRAIN';
        if ($widgetId !== '') {
            $tag['container']['attributes']['data-widget-id'] = $widgetId;
            $tag['publicPlacementId'] = $widgetId;
        }
        // The documented dynamic/lazy-load API is needed when the shared
        // outbrain.js loader is already present and Horus inserts a new widget.
        $tag['initialization'] = ['type' => 'OUTBRAIN_RESEARCH', 'parameters' => []];
        $tag['containerClass'] = 'OUTBRAIN';
        $tag['attributes'] = $tag['container']['attributes'];

        return $tag;
    }

    protected function trustedInitializationForParsedTag(array $parsed): array
    {
        // Preserve the generic fail-closed inline-code check, then map the
        // provider's documented dynamic-widget rescan to a fixed Horus action.
        parent::trustedInitializationForParsedTag($parsed);

        return ['type' => 'OUTBRAIN_RESEARCH', 'parameters' => []];
    }

    protected function allowedInitializationTypes(): array
    {
        return ['NONE', 'OUTBRAIN_RESEARCH'];
    }
}
