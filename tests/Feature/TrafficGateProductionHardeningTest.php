<?php

namespace Tests\Feature;

use Tests\TestCase;

final class TrafficGateProductionHardeningTest extends TestCase
{
    public function test_client_traffic_gate_remains_globally_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('traffic_gate.enabled'));
    }

    public function test_horus_admin_remains_unframeable_while_gate_is_a_separate_static_surface(): void
    {
        $response = $this->get('/admin/login');
        $response->assertOk();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('frame-ancestors https:', $csp);
        $this->assertStringNotContainsString('https://verify.horusmedia.net', $csp);
    }
}
