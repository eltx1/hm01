<?php

namespace App\Services\Demand;

use App\Models\DemandPlacement;
use RuntimeException;

final class TaboolaConnector extends AbstractDemandConnector
{
    protected function code(): string
    {
        return 'TABOOLA';
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
        $configuration = $this->mergedConfiguration($placement, $this->widget($placement));
        $mode = trim((string) ($configuration['taboola_mode'] ?? ''));
        $placementName = trim((string) ($configuration['taboola_placement'] ?? ''));
        $targetType = trim((string) ($configuration['taboola_target_type'] ?? ''));
        $container = trim((string) ($configuration['container_id'] ?? data_get($tag, 'container.id') ?? ''));

        if ($mode !== '' && $placementName !== '' && $targetType !== '' && $container !== '') {
            $tag['initialization'] = [
                'type' => 'TABOOLA_QUEUE',
                'parameters' => [
                    'mode' => $mode,
                    'container' => $container,
                    'placement' => $placementName,
                    'target_type' => $targetType,
                ],
            ];
        }

        return $tag;
    }

    protected function trustedInitializationForParsedTag(array $parsed): array
    {
        $inline = (array) ($parsed['inlineCode'] ?? []);
        if ($inline === []) {
            return ['type' => 'NONE', 'parameters' => []];
        }

        $placementObject = null;
        foreach ($inline as $code) {
            $remaining = (string) $code;
            $remaining = preg_replace('/(?:window\.)?_taboola\s*=\s*(?:window\.)?_taboola\s*\|\|\s*\[\]\s*;?/i', '', $remaining) ?? $remaining;
            $remaining = preg_replace_callback('/(?:window\.)?_taboola\.push\s*\(\s*\{(.*?)\}\s*\)\s*;?/is', function (array $match) use (&$placementObject): string {
                $body = (string) ($match[1] ?? '');
                if (preg_match('/\bflush\s*:\s*true\b/i', $body)) {
                    return '';
                }
                $parameters = [];
                foreach (['mode', 'container', 'placement', 'target_type'] as $key) {
                    if (! preg_match('/(?:^|,)\s*["\']?'.preg_quote($key, '/').'["\']?\s*:\s*(["\'])(.*?)\1\s*(?:,|$)/is', ','.$body.',', $value)) {
                        throw new RuntimeException('Taboola placement queue is missing provider-supplied '.$key.'.');
                    }
                    $parameters[$key] = trim((string) $value[2]);
                    if ($parameters[$key] === '') {
                        throw new RuntimeException('Taboola placement queue contains an empty '.$key.'.');
                    }
                }
                if ($placementObject !== null) {
                    throw new RuntimeException('Paste one Taboola placement recipe at a time.');
                }
                $placementObject = $parameters;

                return '';
            }, $remaining) ?? $remaining;

            if (trim(preg_replace('/\s+/', '', $remaining) ?? '') !== '') {
                throw new RuntimeException('Taboola inline code contains unsupported statements.');
            }
        }

        if ($placementObject === null) {
            throw new RuntimeException('No documented Taboola placement queue was detected.');
        }

        return ['type' => 'TABOOLA_QUEUE', 'parameters' => $placementObject];
    }

    protected function allowedInitializationTypes(): array
    {
        return ['NONE', 'TABOOLA_QUEUE'];
    }
}
