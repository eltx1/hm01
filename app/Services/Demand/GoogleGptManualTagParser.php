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

        $program = $this->parseCanonicalProgram($inline);
        $adUnitPath = $program['adUnitPath'];
        $slotContainerId = $program['containerId'];
        if (! preg_match('#^/[0-9]{1,20}/[A-Za-z0-9_.\-/]{1,240}$#', $adUnitPath)) {
            throw new RuntimeException('The Google GPT ad unit path is not a supported static GAM ad unit path.');
        }
        if (! hash_equals($containerId, $slotContainerId)) {
            throw new RuntimeException('The Google GPT defineSlot container does not match the pasted ad container.');
        }
        if (! hash_equals($containerId, $program['displayContainerId'])) {
            throw new RuntimeException('Google GPT Quick Monetize requires exactly one static googletag.display(...) call for the same container.');
        }

        $decoded = json_decode($program['sizesJson'], true);
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

    /**
     * Quick Monetize intentionally supports only the canonical static GPT
     * program that Horus can reconstruct exactly. This parser consumes the
     * complete inline program, not just interesting method names, so comments,
     * conditionals, boolean guards, assignments, targeting/mapping modifiers,
     * or any other surrounding behavior cannot be silently discarded.
     *
     * One or more canonical cmd.push(function () { ... }) blocks are accepted
     * because Google's generated tags commonly put defineSlot/enableServices
     * and display in separate callbacks. The operation order is preserved across
     * those callbacks and must be defineSlot -> enableServices -> display.
     *
     * @return array{adUnitPath:string,containerId:string,sizesJson:string,displayContainerId:string}
     */
    private function parseCanonicalProgram(string $inline): array
    {
        $source = trim($inline);
        $initializer = '/\A\s*(?:window\s*\.\s*)?googletag\s*=\s*(?:window\s*\.\s*)?googletag\s*\|\|\s*\{\s*cmd\s*:\s*\[\s*\]\s*\}\s*;?/s';
        if (preg_match($initializer, $source, $match) === 1) {
            $source = substr($source, strlen($match[0]));
        }

        $define = null;
        $display = null;
        $enableServicesCount = 0;
        $blockCount = 0;
        $operations = [];

        while (trim($source) !== '') {
            $push = '/\A\s*googletag\s*\.\s*cmd\s*\.\s*push\s*\(\s*function\s*\(\s*\)\s*\{\s*(.*?)\s*\}\s*\)\s*;?/s';
            if (preg_match($push, $source, $block) !== 1) {
                throw new RuntimeException('Google GPT Quick Monetize accepts only the canonical static googletag.cmd.push(function () { ... }) program. Use Advanced setup for conditional or custom GPT code.');
            }

            $blockCount++;
            if ($blockCount > 4) {
                throw new RuntimeException('Google GPT Quick Monetize contains too many initialization callbacks. Use Advanced setup.');
            }
            $this->consumeCanonicalBody((string) $block[1], $define, $display, $enableServicesCount, $operations);
            $source = substr($source, strlen($block[0]));
        }

        if ($define === null || $display === null || $enableServicesCount !== 1) {
            throw new RuntimeException('Google GPT Quick Monetize requires exactly one static defineSlot/addService, enableServices, and display flow.');
        }
        if ($operations !== ['define', 'enable', 'display']) {
            throw new RuntimeException('Google GPT Quick Monetize requires the canonical operation order: defineSlot/addService, then enableServices, then display. Use Advanced setup for custom GPT sequencing.');
        }

        return [
            'adUnitPath' => $define['adUnitPath'],
            'containerId' => $define['containerId'],
            'sizesJson' => $define['sizesJson'],
            'displayContainerId' => $display,
        ];
    }

    /**
     * @param array{adUnitPath:string,containerId:string,sizesJson:string}|null $define
     * @param array<int, string> $operations
     */
    private function consumeCanonicalBody(string $body, ?array &$define, ?string &$display, int &$enableServicesCount, array &$operations): void
    {
        $offset = 0;
        $length = strlen($body);
        while ($offset < $length) {
            if (preg_match('/\G\s+/s', $body, $whitespace, 0, $offset) === 1) {
                $offset += strlen($whitespace[0]);
                if ($offset >= $length) {
                    break;
                }
            }

            $definePattern = '/\G\s*googletag\s*\.\s*defineSlot\s*\(\s*([\'\"])(\/[^\'\"]+)\1\s*,\s*(\[[0-9,\s\[\]]+\])\s*,\s*([\'\"])([^\'\"]+)\4\s*\)\s*\.\s*addService\s*\(\s*googletag\s*\.\s*pubads\s*\(\s*\)\s*\)\s*;?/s';
            if (preg_match($definePattern, $body, $match, 0, $offset) === 1) {
                if ($define !== null) {
                    throw new RuntimeException('Google GPT Quick Monetize requires exactly one static googletag.defineSlot(...) call.');
                }
                $define = [
                    'adUnitPath' => trim((string) $match[2]),
                    'sizesJson' => (string) $match[3],
                    'containerId' => trim((string) $match[5]),
                ];
                $operations[] = 'define';
                $offset += strlen($match[0]);
                continue;
            }

            if (preg_match('/\G\s*googletag\s*\.\s*enableServices\s*\(\s*\)\s*;?/s', $body, $match, 0, $offset) === 1) {
                $enableServicesCount++;
                if ($enableServicesCount > 1) {
                    throw new RuntimeException('Google GPT Quick Monetize requires exactly one googletag.enableServices() call.');
                }
                $operations[] = 'enable';
                $offset += strlen($match[0]);
                continue;
            }

            $displayPattern = '/\G\s*googletag\s*\.\s*display\s*\(\s*([\'\"])([^\'\"]+)\1\s*\)\s*;?/s';
            if (preg_match($displayPattern, $body, $match, 0, $offset) === 1) {
                if ($display !== null) {
                    throw new RuntimeException('Google GPT Quick Monetize requires exactly one static googletag.display(...) call.');
                }
                $display = trim((string) $match[2]);
                $operations[] = 'display';
                $offset += strlen($match[0]);
                continue;
            }

            throw new RuntimeException('Google GPT Quick Monetize contains conditional, commented, modified, or otherwise unsupported GPT code. Use Advanced setup for custom GPT behavior.');
        }
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
