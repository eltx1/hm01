<?php

namespace App\Services\Demand;

use App\Models\DemandPlacement;
use RuntimeException;

final class MgidConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'MGID';
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
            ?: $configuration['widget_id'] ?? null
            ?: $widget?->remote_widget_id
            ?: $placement->remote_placement_id
            ?: ''
        ));

        $tag['container']['class'] = ($tag['container']['class'] ?? '') === 'hm-direct-demand-container'
            ? 'mgbox'
            : ($tag['container']['class'] ?? 'mgbox');
        $tag['container']['attributes'] = ['data-type' => '_mgwidget'] + (array) ($tag['container']['attributes'] ?? []);
        if ($widgetId !== '') {
            $tag['container']['attributes']['data-widget-id'] = $widgetId;
            $tag['publicPlacementId'] = $widgetId;
        }
        $tag['initialization'] = ['type' => 'MGID_QUEUE_LOAD', 'parameters' => []];

        // Legacy compatibility fields.
        $tag['containerClass'] = $tag['container']['class'];
        $tag['attributes'] = $tag['container']['attributes'];

        return $tag;
    }

    protected function trustedInitializationForParsedTag(array $parsed): array
    {
        $inline = (array) ($parsed['inlineCode'] ?? []);
        if ($inline === []) {
            return ['type' => 'MGID_QUEUE_LOAD', 'parameters' => []];
        }

        foreach ($inline as $code) {
            $normalized = preg_replace('/\s+/', '', (string) $code) ?: '';
            $safe = preg_match('/^(?:window\.)?_mgq=(?:window\.)?_mgq\|\|\[\];(?:window\.)?_mgq\.push\(\[["\']_mgc\.load["\']\]\);?$/i', $normalized)
                || preg_match('/^(?:window\.)?_mgq\.push\(\[["\']_mgc\.load["\']\]\);?$/i', $normalized);
            if (! $safe) {
                throw new RuntimeException('MGID inline code is not the documented queue-load recipe.');
            }
        }

        return ['type' => 'MGID_QUEUE_LOAD', 'parameters' => []];
    }

    protected function allowedInitializationTypes(): array
    {
        return ['NONE', 'MGID_QUEUE_LOAD'];
    }
}
