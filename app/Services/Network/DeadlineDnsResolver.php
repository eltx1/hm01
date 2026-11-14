<?php

namespace App\Services\Network;

use App\Services\Network\Contracts\DnsResolver;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use RuntimeException;

/** Bounded public DNS for explicit page diagnostics only; existing resolver is unchanged. */
final class DeadlineDnsResolver implements DnsResolver
{
    public function __construct(private readonly int $deadlineNs, private readonly ?ClientInterface $client = null) {}

    public function addresses(string $host): array
    {
        if (hrtime(true) >= $this->deadlineNs) throw new RuntimeException('DNS deadline exceeded.');
        $client = $this->client ?? new Client(['handler' => HandlerStack::create(new CurlHandler)]);
        $addresses = [];
        foreach (['A' => 1, 'AAAA' => 28] as $type => $recordType) {
            $remaining = ($this->deadlineNs - hrtime(true)) / 1000000000;
            if ($remaining <= 0) throw new RuntimeException('DNS deadline exceeded.');
            // Pin the resolver connection too: no synchronous system DNS lookup is needed.
            $response = $client->request('GET', 'https://cloudflare-dns.com/dns-query', [
                'query' => ['name' => $host, 'type' => $type], 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => min(1, $remaining), 'timeout' => min(2, $remaining), 'decode_content' => false,
                'headers' => ['Accept' => 'application/dns-json', 'Accept-Encoding' => 'identity'],
                'curl' => [CURLOPT_RESOLVE => ['cloudflare-dns.com:443:1.1.1.1'],
                    CURLOPT_CONNECT_TO => ['cloudflare-dns.com:443:1.1.1.1:443'], CURLOPT_MAXFILESIZE => 65536],
                'progress' => static function ($total, $downloaded): void {
                    if ($total > 65536 || $downloaded > 65536) throw new RuntimeException('DNS response exceeds limit.');
                },
            ]);
            if (hrtime(true) >= $this->deadlineNs || $response->getStatusCode() !== 200) throw new RuntimeException('DNS lookup unavailable.');
            $body = (string) $response->getBody();
            if (strlen($body) > 65536) throw new RuntimeException('DNS response exceeds limit.');
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (($data['Status'] ?? null) !== 0 || ! is_array($data['Answer'] ?? [])) throw new RuntimeException('DNS answer unavailable.');
            $answers = $data['Answer'] ?? [];
            if (count($answers) > 64) throw new RuntimeException('Too many DNS answers.');
            foreach ($answers as $answer) {
                if (($answer['type'] ?? null) !== $recordType) continue;
                $ip = $answer['data'] ?? null;
                if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Invalid DNS address.');
                $addresses[] = $ip;
            }
        }

        return array_values(array_unique($addresses));
    }
}
