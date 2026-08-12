<?php

namespace App\Services\Demand;

final class DirectTagRecipeParser
{
    private const SENSITIVE = '/(?:secret|password|credential|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key)/i';

    /**
     * Parse markup only. This service never executes JavaScript and never turns
     * inline code into a callable runtime action by itself.
     *
     * @return array<string, mixed>
     */
    public function parse(string $markup): array
    {
        $markup = trim($markup);
        $warnings = [];
        $scripts = [];
        $inline = [];
        $containers = [];
        $sensitive = false;

        if ($markup === '') {
            return $this->result([], [], [], ['The supplied tag is empty.'], true);
        }
        if (strlen($markup) > 100_000) {
            return $this->result([], [], [], ['The supplied tag exceeds the safe import size.'], true);
        }
        if (preg_match('/javascript\s*:/i', $markup)) {
            $warnings[] = 'javascript: URLs are not permitted.';
        }
        if (preg_match('/(?:env|file)\s*:/i', $markup) || preg_match(self::SENSITIVE, $markup)) {
            $warnings[] = 'Secret-like or credential-reference material is not permitted in public tags.';
            $sensitive = true;
        }
        if (preg_match('/<\s*(?:object|embed|base|meta)\b/i', $markup)) {
            $warnings[] = 'Unsupported active HTML element detected.';
        }
        if (preg_match('/\bon[a-z]+\s*=/i', $markup)) {
            $warnings[] = 'Inline event-handler attributes are not permitted.';
        }

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script\s*>/is', $markup, $scriptMatches, PREG_SET_ORDER);
        foreach ($scriptMatches as $match) {
            $attributes = $this->attributes($match[1] ?? '');
            $src = trim((string) ($attributes['src'] ?? ''));
            $body = trim((string) ($match[2] ?? ''));
            unset($attributes['src']);

            if ($src !== '') {
                if (! $this->httpsUrl($src)) {
                    $warnings[] = 'Every external script must use an absolute HTTPS URL.';
                }
                $scripts[] = [
                    'url' => $src,
                    'async' => array_key_exists('async', $attributes),
                    'defer' => array_key_exists('defer', $attributes),
                    'attributes' => $this->publicAttributes($attributes),
                ];
            }
            if ($body !== '') {
                $inline[] = $body;
            }
        }

        preg_match_all('/<([a-z][a-z0-9:-]*)\b([^>]*)>/i', $markup, $elementMatches, PREG_SET_ORDER);
        foreach ($elementMatches as $match) {
            $element = strtolower((string) ($match[1] ?? ''));
            if (in_array($element, ['script', 'html', 'head', 'body', 'aside'], true)) {
                continue;
            }
            $attributes = $this->attributes($match[2] ?? '');
            $id = isset($attributes['id']) ? (string) $attributes['id'] : null;
            $class = isset($attributes['class']) ? (string) $attributes['class'] : null;
            unset($attributes['id'], $attributes['class']);
            $containers[] = [
                'element' => $element,
                'id' => $id,
                'class' => $class,
                'attributes' => $this->publicAttributes($attributes),
            ];
        }

        if ($scripts === []) {
            $warnings[] = 'No external provider script was detected.';
        }
        if ($containers === []) {
            $warnings[] = 'No render container was detected.';
        }
        if (count($containers) > 1) {
            $warnings[] = 'Multiple candidate containers were detected; an administrator must select the intended render surface.';
        }

        foreach ($scripts as $script) {
            if (preg_match(self::SENSITIVE, json_encode($script, JSON_UNESCAPED_SLASHES) ?: '')) {
                $sensitive = true;
                $warnings[] = 'Secret-like script attributes are not permitted.';
                break;
            }
        }
        foreach ($containers as $container) {
            if (preg_match(self::SENSITIVE, json_encode($container, JSON_UNESCAPED_SLASHES) ?: '')) {
                $sensitive = true;
                $warnings[] = 'Secret-like container attributes are not permitted.';
                break;
            }
        }

        return $this->result($scripts, $containers, $inline, array_values(array_unique($warnings)), $sensitive);
    }

    /** @return array<string, mixed> */
    private function result(array $scripts, array $containers, array $inline, array $warnings, bool $sensitive): array
    {
        return [
            'detectedScripts' => $scripts,
            'detectedContainers' => $containers,
            'detectedPublicIdentifiers' => $this->identifiers($containers),
            'unsupportedInlineCode' => array_map(fn (string $code): string => mb_substr(preg_replace('/\s+/', ' ', $code) ?: '', 0, 240), $inline),
            'inlineCode' => $inline,
            'securityWarnings' => $warnings,
            'containsSensitiveMaterial' => $sensitive,
        ];
    }

    /** @return array<string, string> */
    private function attributes(string $source): array
    {
        $attributes = [];
        preg_match_all('/([A-Za-z_:][-A-Za-z0-9_:.]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/u', $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $name = strtolower((string) $match[1]);
            if ($name === '') {
                continue;
            }
            $value = $match[2] ?? $match[3] ?? $match[4] ?? '';
            $attributes[$name] = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    /** @return array<string, string> */
    private function publicAttributes(array $attributes): array
    {
        $safe = [];
        foreach ($attributes as $key => $value) {
            $key = strtolower((string) $key);
            $value = trim((string) $value);
            if ($key === '' || str_starts_with($key, 'on') || preg_match(self::SENSITIVE, $key)) {
                continue;
            }
            if (! preg_match('/^(?:data-[a-z0-9_.:-]+|aria-[a-z0-9_.:-]+|type|crossorigin|referrerpolicy|async|defer)$/', $key)) {
                continue;
            }
            if (preg_match('/javascript\s*:/i', $value) || preg_match('/^(?:env|file):/i', $value)) {
                continue;
            }
            $safe[$key] = $value;
        }

        return $safe;
    }

    /** @return array<int, string> */
    private function identifiers(array $containers): array
    {
        $ids = [];
        foreach ($containers as $container) {
            foreach (['id', 'data-widget-id', 'data-zone-id', 'data-placement-id'] as $key) {
                $value = $key === 'id' ? ($container['id'] ?? null) : data_get($container, 'attributes.'.$key);
                if (is_string($value) && $value !== '') {
                    $ids[] = $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    private function httpsUrl(string $value): bool
    {
        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        return strtolower((string) parse_url($value, PHP_URL_SCHEME)) === 'https';
    }
}
