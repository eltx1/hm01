<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Network\DeadlineDnsResolver;
use Closure;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use RuntimeException;
use Throwable;

/** Bounded HTML inspection only: scripts are never executed. */
final class PublisherPageChecker
{
    public function __construct(private readonly PublisherPageInspection $inspection, private readonly ?ClientInterface $client = null, private readonly ?Closure $dnsFactory = null) {}

    public function check(Site $site, string $url): array
    {
        $started = hrtime(true);
        $hosts = $site->domains()->where('verification_status', 'VERIFIED')->pluck('domain')->push($site->primary_domain)
            ->map(fn ($host) => strtolower(rtrim($host, '.')))->unique()->all();
        $http = null;
        $finish = function (string $code) use ($site, $started, &$http): array { return ['site_id' => $site->id, 'code' => $code, 'http_status' => $http,
            'checked_at_utc' => now()->utc()->toIso8601String(), 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)]; };
        if (! function_exists('curl_init')) return $finish('CHECK_TRANSPORT_UNAVAILABLE');
        $deadline = $started + 10000000000;
        $dns = $this->dnsFactory ? ($this->dnsFactory)($deadline) : new DeadlineDnsResolver($deadline);
        $urls = new RemoteUrlSafetyValidator($dns);
        $client = $this->client ?? new Client(['handler' => HandlerStack::create(new CurlHandler)]);
        for ($hop = 0; $hop <= 3; $hop++) {
            if ((hrtime(true) - $started) > 10000000000) return $finish('CHECK_TIMEOUT');
            $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
            if (! in_array($host, $hosts, true)) return $finish('UNAUTHORIZED_HOST');
            try {
                $url = (string) (new Uri($url))->withHost($host)->withFragment('');
                $addresses = $urls->publicAddresses($url, 'page_url');
                if (hrtime(true) >= $deadline) return $finish('CHECK_TIMEOUT');
                $address = collect($addresses)->first(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $addresses[0];
                if (str_contains($address, ':')) $address = '['.$address.']';
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
                $remaining = max(0.1, 10 - ((hrtime(true) - $started) / 1000000000));
                // Explicit cURL transport is required: stream-handler fallback would ignore DNS pinning.
                $response = $client->request('GET', $url, [
                    'allow_redirects' => false, 'http_errors' => false, 'stream' => false,
                    'connect_timeout' => min(2, $remaining), 'timeout' => min(5, $remaining),
                    'decode_content' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$address], CURLOPT_CONNECT_TO => [$host.':'.$port.':'.$address.':'.$port], CURLOPT_MAXFILESIZE => 524288],
                    'headers' => ['User-Agent' => 'HorusMedia-InstallationCheck/1.0', 'Accept' => 'text/html', 'Accept-Encoding' => 'identity'],
                    'progress' => static function ($total, $downloaded): void {
                        if ($total > 524288 || $downloaded > 524288) throw new RuntimeException('Response exceeds inspection limit.');
                    },
                ]);
                $http = $response->getStatusCode();
                if (in_array($http, [301, 302, 303, 307, 308], true)) {
                    if ($response->getHeaderLine('Location') === '') return $finish('UNSAFE_REDIRECT');
                    $response->getBody()->close();
                    $next = (string) UriResolver::resolve(new Uri($url), new Uri((string) $response->getHeaderLine('Location')));
                    if (strlen($next) > 2048 || ($scheme === 'https' && strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https')) return $finish('UNSAFE_REDIRECT');
                    $url = $next;
                    continue;
                }
                if ($http < 200 || $http >= 300) return $finish('HTTP_NOT_SUCCESSFUL');
                if ($response->getHeaderLine('Content-Encoding') !== '' && strtolower($response->getHeaderLine('Content-Encoding')) !== 'identity') return $finish('UNSUPPORTED_ENCODING');
                if (! str_starts_with(strtolower((string) $response->getHeaderLine('Content-Type')), 'text/html')) return $finish('NOT_HTML');
                if ((int) $response->getHeaderLine('Content-Length') > 524288) return $finish('PAGE_TOO_LARGE');
                $stream = $response->getBody();
                if ($stream->isSeekable()) $stream->rewind();
                $html = '';
                while (! $stream->eof() && strlen($html) <= 524288) {
                    if ((hrtime(true) - $started) > 10000000000) return $finish('CHECK_TIMEOUT');
                    $chunk = $stream->read(min(8192, 524289 - strlen($html)));
                    if ($chunk === '') break;
                    $html .= $chunk;
                }
                if (strlen($html) > 524288 || ! $stream->eof()) return $finish('PAGE_TOO_LARGE');
                return $finish($this->inspection->inspectHtml($html, $site->public_key, (string) config('horus.loader_url'), $scheme));
            } catch (Throwable) {
                return $finish('FETCH_UNAVAILABLE_OR_UNSAFE');
            }
        }

        return $finish('REDIRECT_LIMIT');
    }
}
