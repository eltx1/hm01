<?php

namespace App\Services\Thoth;

use App\Models\Site;
use App\Services\Sites\DomainSafetyValidator;
use App\Services\Sites\SiteAdsTxtInstallationService;
use DOMDocument;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class SiteEvidenceCollector
{
    public function __construct(
        private readonly DomainSafetyValidator $safety,
        private readonly SiteAdsTxtInstallationService $adsTxt,
    ) {}

    public function collect(Site $site): array
    {
        $site->loadMissing(['publisher', 'domains']);

        [$pages, $gaps] = $this->collectPages($site->primary_domain);

        try {
            $coreAdsTxtVerified = $this->adsTxt->hasCurrentCoreVerification($site);
        } catch (Throwable) {
            $coreAdsTxtVerified = false;
            $gaps[] = 'Horus HMP/HMS ads.txt verification state could not be read during the advisory.';
        }

        $primaryDomain = $site->domains->first(fn ($domain) => $domain->is_primary && $domain->domain === $site->primary_domain);

        return [
            'evidence_version' => 'site-quality-evidence-v1',
            'review_context' => 'WEBSITE_REVIEW',
            'advisory_only' => true,
            'publisher' => [
                'display_name' => $site->publisher->display_name,
                'business_domain' => $site->publisher->business_domain,
            ],
            'site' => [
                'id' => $site->id,
                'display_name' => $site->display_name,
                'primary_domain' => $site->primary_domain,
                'status' => $site->status->value,
                'language' => $site->language,
                'content_category' => $site->content_category,
                'country' => $site->country,
                'submitted_at' => $site->submitted_at?->toISOString(),
                'domain_verification_status' => $primaryDomain?->verification_status ?? 'UNKNOWN',
                'horus_core_ads_txt_verified' => $coreAdsTxtVerified,
                'production_activation_allowed_by_ai' => false,
            ],
            'website_evidence' => $pages,
            'evidence_gaps' => array_values(array_unique(array_merge(
                $gaps,
                $pages === [] ? ['The submitted website returned no acceptable static HTML evidence. Human review can continue without THOTH.'] : [],
            ))),
        ];
    }

    /** @return array{0: array<int, array<string, string>>, 1: array<int, string>} */
    private function collectPages(string $domain): array
    {
        $pages = [];
        $gaps = [];
        $maxPages = max(1, (int) config('thoth.evidence.max_pages', 4));
        $maxTotalText = max(1, (int) config('thoth.evidence.max_total_text_chars', 60000));
        $totalText = 0;
        $groups = [
            'homepage' => ['/'],
            'privacy' => ['/privacy', '/privacy-policy'],
            'about' => ['/about', '/about-us'],
            'contact' => ['/contact', '/contact-us'],
        ];

        foreach ($groups as $label => $paths) {
            if (count($pages) >= $maxPages || $totalText >= $maxTotalText) {
                break;
            }

            $found = false;
            foreach ($paths as $path) {
                try {
                    $page = $this->fetch($domain, $path);
                } catch (Throwable) {
                    $page = null;
                }
                if ($page === null) {
                    continue;
                }

                $remaining = max(0, $maxTotalText - $totalText);
                if ($remaining === 0) {
                    break;
                }
                $page['visible_text'] = mb_substr($page['visible_text'], 0, $remaining);
                if ($page['visible_text'] === '') {
                    continue;
                }
                $totalText += mb_strlen($page['visible_text']);
                $pages[] = $page;
                $found = true;
                break;
            }

            if (! $found) {
                $gaps[] = ucfirst($label).' page unavailable or did not return acceptable static HTML for '.$domain.'.';
            }
        }

        return [$pages, $gaps];
    }

    private function fetch(string $domain, string $path): ?array
    {
        foreach (['https', 'http'] as $scheme) {
            $url = $scheme.'://'.$domain.$path;
            $response = $this->requestWithSafeRedirects($url, $domain);
            if ($response === null) {
                continue;
            }

            [$finalUrl, $http] = $response;
            $type = strtolower((string) $http->header('Content-Type'));
            if (! $http->successful() || ! str_contains($type, 'text/html')) {
                continue;
            }

            $body = $this->readBoundedBody($http);
            if ($body === null) {
                continue;
            }
            $visibleText = mb_substr(
                $this->visibleText($body),
                0,
                max(1, (int) config('thoth.evidence.max_text_chars', 30000)),
            );
            if ($visibleText === '') {
                continue;
            }

            return [
                'url' => $finalUrl,
                'title' => $this->title($body),
                'visible_text' => $visibleText,
            ];
        }

        return null;
    }

    private function readBoundedBody(Response $response): ?string
    {
        $max = max(1, (int) config('thoth.evidence.max_bytes_per_page', 262144));
        $declaredBytes = (int) ($response->header('Content-Length') ?: 0);
        if ($declaredBytes > $max) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (! $stream->eof() && strlen($body) <= $max) {
            $remaining = ($max + 1) - strlen($body);
            if ($remaining <= 0) {
                break;
            }
            $chunk = $stream->read(min(8192, $remaining));
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        if (strlen($body) > $max || ! $stream->eof()) {
            return null;
        }

        return mb_scrub($body, 'UTF-8');
    }

    /** @return array{0: string, 1: Response}|null */
    private function requestWithSafeRedirects(string $url, string $submittedDomain): ?array
    {
        $maxRedirects = max(0, (int) config('thoth.evidence.max_redirects', 3));
        $current = $url;

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $parts = parse_url($current);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! $this->sameSite($host, $submittedDomain)) {
                return null;
            }

            $addresses = $this->safety->assertSafe($host);
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $resolve = defined('CURLOPT_RESOLVE') ? [
                'curl' => [CURLOPT_RESOLVE => array_map(
                    fn ($ip) => $host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip),
                    $addresses,
                )],
            ] : [];

            $http = Http::timeout(max(1, (int) config('thoth.evidence.timeout_seconds', 8)))
                ->connectTimeout(max(1, (int) config('thoth.evidence.connect_timeout_seconds', 4)))
                ->withoutRedirecting()
                ->withOptions(array_merge($resolve, ['stream' => true]))
                ->get($current);

            if (! in_array($http->status(), [301, 302, 303, 307, 308], true)) {
                return [$current, $http];
            }

            if ($redirects === $maxRedirects) {
                return null;
            }

            $next = $this->resolveRedirect($current, trim((string) $http->header('Location')));
            if ($next === null) {
                return null;
            }
            $nextHost = strtolower(rtrim((string) parse_url($next, PHP_URL_HOST), '.'));
            if ($nextHost === '' || ! $this->sameSite($nextHost, $submittedDomain)) {
                return null;
            }
            $current = $next;
        }

        return null;
    }

    private function sameSite(string $candidate, string $submittedDomain): bool
    {
        $normalize = fn (string $host): string => preg_replace('/^www\./i', '', strtolower(rtrim($host, '.'))) ?? '';

        return $normalize($candidate) === $normalize($submittedDomain);
    }

    private function resolveRedirect(string $current, string $location): ?string
    {
        if ($location === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($current);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $port = $parts['port'] ?? null;
        if (! $scheme || ! $host) {
            return null;
        }
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = (string) ($parts['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $origin.($directory === '' || $directory === '.' ? '/' : $directory.'/').$location;
    }

    private function title(string $html): string
    {
        preg_match('#<title[^>]*>(.*?)</title>#is', $html, $matches);

        return mb_substr(trim(html_entity_decode(strip_tags($matches[1] ?? ''))), 0, 300);
    }

    private function visibleText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument;
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach (['script', 'style', 'noscript', 'iframe', 'object', 'embed', 'form'] as $tag) {
            while (($nodes = $dom->getElementsByTagName($tag))->length > 0) {
                $nodes->item(0)?->parentNode?->removeChild($nodes->item(0));
            }
        }
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//*[@hidden or @aria-hidden="true" or contains(translate(@style, "ABCDEFGHIJKLMNOPQRSTUVWXYZ ", "abcdefghijklmnopqrstuvwxyz"), "display:none")]') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode((string) $dom->textContent)) ?? '');
    }
}
