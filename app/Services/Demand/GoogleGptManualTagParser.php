<?php

namespace App\Services\Demand;

use RuntimeException;

final class GoogleGptManualTagParser
{
    private const GPT_SCRIPT_URLS = [
        'https://securepubads.g.doubleclick.net/tag/js/gpt.js',
        'https://pagead2.googlesyndication.com/tag/js/gpt.js',
    ];

    /**
     * Return null when the tag is not Google GPT. A GPT-looking tag that cannot
     * be normalized safely fails closed instead of falling back to raw execution.
     *
     * @return array{scriptUrl:string,adUnitPath:string,containerId:string,sizes:array<int,array{0:int,1:int}>}|null
     */
    public function parse(string $tag): ?array
    {
        $parsed = (new DirectTagRecipeParser())->parse($tag);
        $scripts = collect((array) ($parsed['detectedScripts'] ?? []))
            ->map(fn (array $script): string => trim((string) ($script['url'] ?? '')))
            ->filter()
            ->values();

        $gptScripts = $scripts
            ->filter(fn (string $url): bool => in_array($url, self::GPT_SCRIPT_URLS, true))
            ->values();

        if ($gptScripts->isEmpty()) {
            return null;
        }

        $warnings = array_values((array) ($parsed['securityWarnings'] ?? []));
        if ((bool) ($parsed['containsSensitiveMaterial'] ?? false) || $warnings !== []) {
            throw new RuntimeException(
                $warnings !== []
                    ? implode(' ', $warnings)
                    : 'The Google GPT tag contains private or unsafe material.'
            );
        }

        if ($scripts->count() !== 1 || $gptScripts->count() !== 1) {
            throw new RuntimeException('Quick Monetize accepts Google GPT tags with exactly one official GPT library script. Use Advanced setup for tags that load additional scripts.');
        }

        $containers = (array) ($parsed['detectedContainers'] ?? []);
        if (count($containers) !== 1) {
            throw new RuntimeException('Google GPT Quick Monetize requires exactly one ad container.');
        }

        $containerId = trim((string) ($containers[0]['id'] ?? ''));
        if ($containerId === '' || ! preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $containerId)) {
            throw new RuntimeException('Google GPT Quick Monetize requires one static container id using only letters, numbers, underscore, or hyphen.');
        }

        $inline = implode("\n", array_map('strval', (array) ($parsed['inlineCode'] ?? [])));
        if ($inline === '') {
            throw new RuntimeException('Google GPT Quick Monetize could not find the slot definition.');
        }

        $pattern = '/googletag\s*\.\s*defineSlot\s*\(\s*([\'\"])(\/[^\'\"]+)\1\s*,\s*(\[[0-9,\s\[\]]+\])\s*,\s*([\'\"])([^\'\"]+)\4\s*\)/s';
        if (preg_match_all($pattern, $inline, $matches, PREG_SET_ORDER) !== 1) {
            throw new RuntimeException('Google GPT Quick Monetize requires exactly one static googletag.defineSlot(...) call.');
        }

        $adUnitPath = trim((string) $matches[0][2]);
        $slotContainerId = trim((string) $matches[0][5]);
        if (! preg_match('#^/[0-9]{1,20}/[A-Za-z0-9_.\-/]{1,240}$#', $adUnitPath)) {
            throw new RuntimeException('The Google GPT ad unit path is not a supported static GAM ad unit path.');
        }
        if (! hash_equals($containerId, $slotContainerId)) {
            throw new RuntimeException('The Google GPT defineSlot container does not match the pasted ad container.');
        }

        $displayPattern = '/googletag\s*\.\s*display\s*\(\s*([\'\"])([^\'\"]+)\1\s*\)/s';
        if (preg_match_all($displayPattern, $inline, $displayMatches, PREG_SET_ORDER) !== 1
            || ! hash_equals($containerId, trim((string) $displayMatches[0][2]))) {
            throw new RuntimeException('Google GPT Quick Monetize requires exactly one static googletag.display(...) call for the same container.');
        }

        $decoded = json_decode((string) $matches[0][3], true);
        $sizes = $this->normalizeSizes($decoded);
        if ($sizes === []) {
            throw new RuntimeException('The Google GPT slot has no supported fixed display size.');
        }

        return [
            'scriptUrl' => $gptScripts->first(),
            'adUnitPath' => $adUnitPath,
            'containerId' => $containerId,
            'sizes' => $sizes,
        ];
    }

    /** @return array<int, array{0:int,1:int}> */
    private function normalizeSizes(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        if (count($value) === 2 && is_numeric($value[0] ?? null) && is_numeric($value[1] ?? null)) {
            $value = [$value];
        }

        $sizes = [];
        foreach ($value as $size) {
            if (! is_array($size) || count($size) !== 2 || ! is_numeric($size[0] ?? null) || ! is_numeric($size[1] ?? null)) {
                return [];
            }

            $width = (int) $size[0];
            $height = (int) $size[1];
            if ($width < 1 || $width > 10000 || $height < 1 || $height > 10000) {
                return [];
            }

            $sizes[] = [$width, $height];
            if (count($sizes) > 20) {
                return [];
            }
        }

        return collect($sizes)
            ->unique(fn (array $size): string => $size[0].'x'.$size[1])
            ->values()
            ->all();
    }
}
