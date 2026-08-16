<?php

namespace App\Services\Thoth;

use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherQualityProfile;
use App\Models\User;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\Sites\DomainSafetyValidator;
use DOMDocument;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PublisherEvidenceCollector
{
    public function __construct(
        private readonly DomainSafetyValidator $safety,
        private readonly ?ApplicationAdsTxtVerificationService $applicationVerification = null,
    ) {}

    /** Backwards-compatible operational Publisher entrypoint. */
    public function collect(Publisher $publisher, PublisherQualityProfile $profile): array
    {
        return $this->collectForPublisher($publisher, $profile);
    }

    public function collectForPublisher(Publisher $publisher, PublisherQualityProfile $profile): array
    {
        $this->assertProfile($publisher, $profile);
        $publisher->loadMissing('sites.domains');
        $verified = $publisher->sites->flatMap->domains
            ->filter(fn ($domain) => $domain->verification_status === 'VERIFIED')
            ->pluck('domain')
            ->map(fn ($domain) => strtolower((string) $domain))
            ->unique()->sort()->values();

        [$pages, $gaps] = $this->collectPages($verified->all());

        return [
            'evidence_version' => 'publisher-quality-evidence-v2',
            'review_context' => 'OPERATIONAL_PUBLISHER',
            'publisher' => $this->publisherContext($publisher),
            'profile' => $this->profileContext($profile),
            'sites' => $publisher->sites->sortBy('primary_domain')->map(fn ($site) => [
                'display_name' => $site->display_name,
                'primary_domain' => $site->primary_domain,
                'language' => $site->language,
                'content_category' => $site->content_category,
                'country' => $site->country,
                'estimated_monthly_pageviews' => $site->estimated_monthly_pageviews,
                'verified_domains' => $site->domains->filter(fn ($d) => $d->verification_status === 'VERIFIED')->pluck('domain')->sort()->values()->all(),
            ])->values()->all(),
            'website_evidence' => $pages,
            'evidence_gaps' => array_values(array_unique(array_merge(
                $gaps,
                $verified->isEmpty() ? ['No verified website domain was available; no website was fetched.'] : ($pages === [] ? ['Verified domains were unavailable or returned no acceptable static HTML.'] : []),
            ))),
        ];
    }

    public function collectForApplication(PublisherApplication $application, PublisherQualityProfile $profile, User $actor): array
    {
        $publisher = Publisher::withoutGlobalScopes()->findOrFail($application->publisher_id);
        $this->assertProfile($publisher, $profile);

        $claim = null;
        $authorizationVerified = false;
        $freshness = 'UNVERIFIED';
        $verificationGap = null;

        try {
            $verifier = $this->applicationVerifier();
            $claim = $verifier->currentClaim($application)->loadMissing(['publisherSeller', 'websiteSeller']);
            $authorizationVerified = $verifier->crawlingEligible($application);

            if ($authorizationVerified) {
                $freshDays = max(1, (int) config('thoth.application_domain_verification_fresh_days', 7));
                $freshCutoff = now()->subDays($freshDays);
                $isFresh = $claim->verified_at !== null && $claim->verified_at->greaterThanOrEqualTo($freshCutoff);

                if ($isFresh) {
                    $freshness = 'FRESH';
                } else {
                    $refresh = $verifier->refreshExistingVerification($application, $actor);
                    $claim = $refresh['claim']->loadMissing(['publisherSeller', 'websiteSeller']);
                    $authorizationVerified = (bool) ($refresh['verified'] ?? false);
                    $freshness = $authorizationVerified ? 'REFRESHED' : 'STALE_REFRESH_FAILED';
                    if (! $authorizationVerified) {
                        $verificationGap = 'Task 39 ads.txt authorization was stale and the fresh canonical verification did not pass; no website content was fetched.';
                    }
                }
            } else {
                $verificationGap = 'The current application domain is not Task 39 ads.txt verified; no website content was fetched.';
            }
        } catch (Throwable) {
            $authorizationVerified = false;
            $verificationGap = 'No eligible Task 39 ads.txt-verified application domain claim was available; no website content was fetched.';
        }

        $pages = [];
        $gaps = [];
        if ($authorizationVerified && $claim !== null) {
            [$pages, $gaps] = $this->collectPages([$claim->normalized_domain]);
        }
        if ($verificationGap !== null) {
            $gaps[] = $verificationGap;
        }
        if ($authorizationVerified && $pages === []) {
            $gaps[] = 'Website authorization is verified, but zero acceptable static HTML pages were obtained; THOTH has applicant declarations only.';
        }

        return [
            'evidence_version' => 'publisher-quality-evidence-v2',
            'review_context' => 'PUBLISHER_APPLICATION',
            'publisher' => $this->publisherContext($publisher),
            'application' => [
                'id' => $application->id,
                'status' => $application->status->value,
                'primary_verified_domain' => $claim?->normalized_domain,
                'website_authorization_verified' => $authorizationVerified,
                'verification_source' => 'HORUS_ADS_TXT',
                'verified_at' => $claim?->verified_at?->toISOString(),
                'verification_freshness' => $freshness,
                'pre_approval' => true,
                'production_site_active' => false,
            ],
            'profile' => $this->profileContext($profile),
            'website_evidence' => $pages,
            'evidence_gaps' => array_values(array_unique($gaps)),
        ];
    }

    /** @return array{0: array<int, array<string, string>>, 1: array<int, string>} */
    private function collectPages(array $domains): array
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

        foreach (array_values(array_unique(array_map(fn ($domain) => strtolower((string) $domain), $domains))) as $domain) {
            foreach ($groups as $label => $paths) {
                if (count($pages) >= $maxPages || $totalText >= $maxTotalText) {
                    break 2;
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
        }

        return [$pages, $gaps];
    }

    private function fetch(string $verifiedDomain, string $path): ?array
    {
        foreach (['https', 'http'] as $scheme) {
            $url = $scheme.'://'.$verifiedDomain.$path;
            $response = $this->requestWithSafeRedirects($url, $verifiedDomain);
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
    private function requestWithSafeRedirects(string $url, string $verifiedDomain): ?array
    {
        $maxRedirects = max(0, (int) config('thoth.evidence.max_redirects', 3));
        $current = $url;

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $parts = parse_url($current);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! $this->sameVerifiedSite($host, $verifiedDomain)) {
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

            $location = trim((string) $http->header('Location'));
            $next = $this->resolveRedirect($current, $location);
            if ($next === null) {
                return null;
            }

            $nextHost = strtolower(rtrim((string) parse_url($next, PHP_URL_HOST), '.'));
            if ($nextHost === '' || ! $this->sameVerifiedSite($nextHost, $verifiedDomain)) {
                return null;
            }
            $current = $next;
        }

        return null;
    }

    private function sameVerifiedSite(string $candidate, string $verifiedDomain): bool
    {
        $normalize = fn (string $host): string => preg_replace('/^www\./i', '', strtolower(rtrim($host, '.'))) ?? '';

        return $normalize($candidate) === $normalize($verifiedDomain);
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

    private function applicationVerifier(): ApplicationAdsTxtVerificationService
    {
        return $this->applicationVerification ?? app(ApplicationAdsTxtVerificationService::class);
    }

    private function assertProfile(Publisher $publisher, PublisherQualityProfile $profile): void
    {
        if ($profile->publisher_id !== $publisher->id) {
            throw ValidationException::withMessages(['profile' => 'The quality profile does not belong to this Publisher.']);
        }
    }

    private function publisherContext(Publisher $publisher): array
    {
        return ['display_name' => $publisher->display_name, 'business_domain' => $publisher->business_domain];
    }

    private function profileContext(PublisherQualityProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'version' => $profile->version,
            'content_categories' => $profile->content_categories,
            'content_description' => $profile->content_description,
            'traffic_profile' => $profile->traffic_profile,
            'audience_countries' => $profile->audience_countries,
            'device_mix' => $profile->device_mix,
            'declarations' => $profile->declarations,
            'review_comments' => $profile->review_comments,
        ];
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
