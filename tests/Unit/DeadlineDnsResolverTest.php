<?php

namespace Tests\Unit;

use App\Services\Network\DeadlineDnsResolver;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DeadlineDnsResolverTest extends TestCase
{
    public function test_expired_deadline_fails_before_creating_a_network_client(): void
    {
        $this->expectException(RuntimeException::class);
        (new DeadlineDnsResolver(hrtime(true) - 1))->addresses('publisher.example.test');
    }

    public function test_public_dns_requests_have_bounded_time_fixed_pinning_and_no_redirects(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['Status' => 0, 'Answer' => [['type' => 1, 'data' => '8.8.8.8']]])),
            new Response(200, [], json_encode(['Status' => 0, 'Answer' => [['type' => 28, 'data' => '2606:4700:4700::1111']]])),
        ]));
        $stack->push(Middleware::history($history));
        $resolver = new DeadlineDnsResolver(hrtime(true) + 10000000000, new Client(['handler' => $stack]));
        $this->assertSame(['8.8.8.8', '2606:4700:4700::1111'], $resolver->addresses('publisher.example.test'));
        $this->assertCount(2, $history);
        foreach ($history as $request) {
            $this->assertFalse($request['options']['allow_redirects']);
            $this->assertLessThanOrEqual(2, $request['options']['timeout']);
            $this->assertSame(['cloudflare-dns.com:443:1.1.1.1:443'], $request['options']['curl'][CURLOPT_CONNECT_TO]);
        }
    }

    public function test_failed_dns_response_is_not_treated_as_an_empty_success(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], '{"Status":2}')]))]);
        $this->expectException(RuntimeException::class);
        (new DeadlineDnsResolver(hrtime(true) + 10000000000, $client))->addresses('publisher.example.test');
    }
}
