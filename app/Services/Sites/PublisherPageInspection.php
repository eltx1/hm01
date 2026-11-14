<?php

namespace App\Services\Sites;

use DOMDocument;

final class PublisherPageInspection
{
    public function inspectHtml(string $html, string $siteKey, string $loaderUrl, string $pageScheme = 'https'): string
    {
        if (trim($html) === '') return 'UNREADABLE_HTML';
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return 'UNREADABLE_HTML';
            $expected = parse_url($loaderUrl);
            $correct = 0; $wrong = false; $missing = false;
            foreach ($document->getElementsByTagName('script') as $script) {
                if (! in_array(strtolower(trim($script->getAttribute('type'))), ['', 'module', 'text/javascript', 'application/javascript'], true)) continue;
                $inert = false;
                for ($parent = $script->parentNode; $parent; $parent = $parent->parentNode) {
                    if (in_array(strtolower($parent->nodeName), ['template', 'noscript', 'textarea', 'iframe', 'xmp', 'style', 'title', 'noembed', 'noframes', 'plaintext', 'script'], true)) { $inert = true; break; }
                }
                if ($inert) continue;
                $src = trim($script->getAttribute('src'));
                if (str_starts_with($src, '//')) $src = $pageScheme.':'.$src;
                $url = parse_url($src);
                if (! is_array($url) || isset($url['user']) || isset($url['pass']) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)) continue;
                $port = $url['port'] ?? (($url['scheme'] ?? '') === 'https' ? 443 : 80);
                $expectedPort = $expected['port'] ?? (($expected['scheme'] ?? '') === 'https' ? 443 : 80);
                if (strtolower($url['host'] ?? '') !== strtolower($expected['host'] ?? '') || $port !== $expectedPort
                    || ($url['scheme'] ?? '') !== ($expected['scheme'] ?? '') || ($url['path'] ?? '/') !== ($expected['path'] ?? '/')) continue;
                $key = $script->getAttribute('data-site-key');
                if ($key === $siteKey) $correct++;
                elseif ($key === '') $missing = true;
                else $wrong = true;
            }
            return match (true) {
                $correct > 1 || ($correct > 0 && ($wrong || $missing)) => 'MULTIPLE_LOADER_TAGS',
                $correct === 1 => 'CORRECT_TAG_FOUND',
                $wrong => 'WRONG_SITE_KEY',
                $missing => 'MISSING_SITE_KEY',
                default => 'LOADER_TAG_NOT_FOUND',
            };
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
