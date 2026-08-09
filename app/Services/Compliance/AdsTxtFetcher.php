<?php

namespace App\Services\Compliance;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Campaigns\RemoteUrlSafetyValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AdsTxtFetcher
{
    public function __construct(private readonly RemoteUrlSafetyValidator $urls) {}

    /** @return array<string, mixed> */
    public function fetch(Site $site): array
    {
        $started = hrtime(true);
        $allowedHosts = SiteDomain::withoutGlobalScope('organization')
            ->where('site_id', $site->id)
            ->where('verification_status', 'VERIFIED')
            ->pluck('domain')->map(fn (string $domain): string => strtolower(rtrim($domain, '.')))->unique()->values();
        $primary = strtolower(rtrim($site->primary_domain, '.'));
        if (! $allowedHosts->contains($primary)) {
            return $this->failure('DOMAIN_NOT_VERIFIED', 'The primary website domain is not verified for safe compliance fetching.', null, null, $started);
        }

        $url = 'https://'.$primary.'/ads.txt';
        $originalUrl = $url;
        $redirects = [];

        for ($hop = 0; $hop <= (int) config('ads-txt.max_redirects', 3); $hop++) {
            $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
            if (! $allowedHosts->contains($host)) {
                return $this->failure('UNAUTHORIZED_REDIRECT', 'The response redirected to a domain that is not verified for this website.', $url, null, $started, $redirects);
            }

            try {
                $addresses = $this->urls->publicAddresses($url, 'ads_txt_url');
                $address = collect($addresses)->first(fn (string $item): bool => filter_var($item, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ?? $addresses[0];
                $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));
                $response = Http::connectTimeout((int) config('ads-txt.connect_timeout_seconds', 3))
                    ->timeout((int) config('ads-txt.timeout_seconds', 8))
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                        'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.$address]],
                    ])
                    ->withHeaders([
                        'User-Agent' => (string) config('ads-txt.user_agent'),
                        'Accept' => 'text/plain',
                    ])->get($url);
            } catch (ConnectionException $exception) {
                return $this->failure('CONNECTION_FAILED', 'The ads.txt request timed out or could not connect.', $url, null, $started, $redirects);
            } catch (Throwable $exception) {
                return $this->failure('UNSAFE_TARGET', 'The ads.txt target could not be fetched safely.', $url, null, $started, $redirects);
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if ($hop >= (int) config('ads-txt.max_redirects', 3)) {
                    return $this->failure('TOO_MANY_REDIRECTS', 'The ads.txt response exceeded the redirect limit.', $url, $response->status(), $started, $redirects);
                }
                $location = trim((string) $response->header('Location'));
                if ($location === '') {
                    return $this->failure('INVALID_REDIRECT', 'The ads.txt redirect had no target.', $url, $response->status(), $started, $redirects);
                }
                $next = $this->resolveRedirect($url, $location);
                if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
                    && strtolower((string) parse_url($next, PHP_URL_SCHEME)) !== 'https') {
                    return $this->failure('INSECURE_REDIRECT', 'The ads.txt endpoint attempted to downgrade an HTTPS request.', $next, $response->status(), $started, $redirects);
                }
                $redirects[] = ['from' => $url, 'to' => $next, 'status' => $response->status()];
                $url = $next;

                continue;
            }

            $contentType = strtolower(trim((string) $response->header('Content-Type')));
            $declaredBytes = (int) ($response->header('Content-Length') ?: 0);
            $maxBytes = (int) config('ads-txt.max_response_bytes', 1_048_576);
            if ($declaredBytes > $maxBytes) {
                return $this->failure('RESPONSE_TOO_LARGE', 'The ads.txt response exceeded the configured size limit.', $url, $response->status(), $started, $redirects, $contentType);
            }
            if (! $response->successful()) {
                return $this->failure('HTTP_'.$response->status(), 'The ads.txt endpoint returned HTTP '.$response->status().'.', $url, $response->status(), $started, $redirects, $contentType);
            }
            if (! str_starts_with($contentType, 'text/plain')) {
                return $this->failure('INVALID_CONTENT_TYPE', 'The ads.txt endpoint must return text/plain.', $url, $response->status(), $started, $redirects, $contentType);
            }

            $stream = $response->toPsrResponse()->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = '';
            while (! $stream->eof() && strlen($body) <= $maxBytes) {
                $chunk = $stream->read(min(8192, ($maxBytes + 1) - strlen($body)));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            $bytes = strlen($body);
            if ($bytes > $maxBytes || ! $stream->eof()) {
                return $this->failure('RESPONSE_TOO_LARGE', 'The ads.txt response exceeded the configured size limit.', $url, $response->status(), $started, $redirects, $contentType);
            }

            return [
                'ok' => true,
                'url' => $originalUrl,
                'final_url' => $url,
                'http_status' => $response->status(),
                'content_type' => $contentType,
                'body' => mb_scrub($body, 'UTF-8'),
                'bytes' => $bytes,
                'duration_ms' => $this->duration($started),
                'redirects' => $redirects,
                'error_code' => null,
                'error' => null,
            ];
        }

        return $this->failure('FETCH_FAILED', 'The ads.txt endpoint could not be fetched.', $url, null, $started, $redirects);
    }

    private function resolveRedirect(string $current, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $scheme = (string) parse_url($current, PHP_URL_SCHEME);
        $host = (string) parse_url($current, PHP_URL_HOST);
        $port = parse_url($current, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');
        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $path = (string) parse_url($current, PHP_URL_PATH);

        return $origin.rtrim(str_replace('\\', '/', dirname($path)), '/').'/'.$location;
    }

    /** @return array<string, mixed> */
    private function failure(
        string $code,
        string $message,
        ?string $url,
        ?int $httpStatus,
        int $started,
        array $redirects = [],
        ?string $contentType = null,
    ): array {
        return [
            'ok' => false,
            'url' => $url,
            'final_url' => $url,
            'http_status' => $httpStatus,
            'content_type' => $contentType,
            'body' => '',
            'bytes' => null,
            'duration_ms' => $this->duration($started),
            'redirects' => $redirects,
            'error_code' => $code,
            'error' => $message,
        ];
    }

    private function duration(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }
}
