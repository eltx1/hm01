<?php

namespace App\Services\Thoth;

use App\Models\Publisher;
use App\Models\PublisherQualityProfile;
use App\Services\Sites\DomainSafetyValidator;
use DOMDocument;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PublisherEvidenceCollector
{
    public function __construct(private readonly DomainSafetyValidator $safety) {}

    public function collect(Publisher $publisher, PublisherQualityProfile $profile): array
    {
        $publisher->loadMissing('sites.domains');
        $verified = $publisher->sites->flatMap->domains->filter(fn ($domain) => $domain->verification_status === 'VERIFIED')->pluck('domain')->map(fn ($domain) => strtolower((string) $domain))->unique()->sort()->values();
        $pages = [];
        $gaps = [];
        foreach ($verified->take((int) config('thoth.evidence.max_pages')) as $domain) {
            foreach (['/', '/privacy', '/about', '/contact'] as $path) {
                if (count($pages) >= (int) config('thoth.evidence.max_pages')) {
                    break 2;
                }
                try {
                    $page = $this->fetch($domain, $path);
                } catch (Throwable) {
                    $gaps[] = 'A verified domain was blocked or unavailable during safe evidence collection.';
                    break;
                }
                if ($page !== null) {
                    $pages[] = $page;
                }
            }
        }

        return [
            'evidence_version' => 'publisher-quality-evidence-v1',
            'publisher' => ['display_name' => $publisher->display_name, 'business_domain' => $publisher->business_domain],
            'profile' => ['id' => $profile->id, 'version' => $profile->version, 'content_categories' => $profile->content_categories, 'content_description' => $profile->content_description, 'traffic_profile' => $profile->traffic_profile, 'audience_countries' => $profile->audience_countries, 'device_mix' => $profile->device_mix, 'declarations' => $profile->declarations, 'review_comments' => $profile->review_comments],
            'sites' => $publisher->sites->sortBy('primary_domain')->map(fn ($site) => ['display_name' => $site->display_name, 'primary_domain' => $site->primary_domain, 'language' => $site->language, 'content_category' => $site->content_category, 'country' => $site->country, 'estimated_monthly_pageviews' => $site->estimated_monthly_pageviews, 'verified_domains' => $site->domains->filter(fn ($d) => $d->verification_status === 'VERIFIED')->pluck('domain')->sort()->values()->all()])->values()->all(),
            'website_evidence' => $pages,
            'evidence_gaps' => array_values(array_unique(array_merge($gaps, $verified->isEmpty() ? ['No verified website domain was available; no website was fetched.'] : ($pages === [] ? ['Verified domains were unavailable or returned no acceptable static HTML.'] : [])))),
        ];
    }

    private function fetch(string $domain, string $path): ?array
    {
        $addresses = $this->safety->assertSafe($domain);
        foreach (['https', 'http'] as $scheme) {
            $port = $scheme === 'https' ? 443 : 80;
            $resolve = defined('CURLOPT_RESOLVE') ? ['curl' => [CURLOPT_RESOLVE => array_map(fn ($ip) => $domain.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip), $addresses)]] : [];
            $response = Http::timeout(8)->connectTimeout(4)->withoutRedirecting()->withOptions(array_merge($resolve, ['stream' => true]))->get($scheme.'://'.$domain.$path);
            $type = strtolower((string) $response->header('content-type'));
            if (! $response->successful() || ! str_contains($type, 'text/html')) {
                continue;
            }
            $max = (int) config('thoth.evidence.max_bytes_per_page');
            $body = $response->toPsrResponse()->getBody()->read($max + 1);
            if (strlen($body) > $max) {
                continue;
            }

            return ['url' => $scheme.'://'.$domain.$path, 'title' => $this->title($body), 'visible_text' => mb_substr($this->visibleText($body), 0, (int) config('thoth.evidence.max_text_chars'))];
        }

        return null;
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
