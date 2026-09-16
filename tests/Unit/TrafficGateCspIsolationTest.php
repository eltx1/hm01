<?php

namespace Tests\Unit;

use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use ReflectionClass;
use Tests\TestCase;

class TrafficGateCspIsolationTest extends TestCase
{
    public function test_traffic_gate_disables_edge_html_transforms_without_weakening_csp(): void
    {
        $builder = app(StaticDeliverySnapshotBuilder::class);
        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('headers');
        $method->setAccessible(true);
        $headers = $method->invoke($builder);

        $this->assertIsString($headers);
        $this->assertStringContainsString("/traffic-gate/*\n  Cache-Control: public, max-age=300, must-revalidate, no-transform", $headers);
        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $headers);
        $this->assertStringContainsString("frame-src https://challenges.cloudflare.com", $headers);
        $this->assertStringContainsString("connect-src 'self' https://challenges.cloudflare.com", $headers);
        $this->assertStringNotContainsString('static.cloudflareinsights.com', $headers);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $headers);
    }
}
