<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StaticEdgePublicParityWorkflowTest extends TestCase
{
    public function test_static_edge_sync_never_trusts_the_control_plane_marker_without_public_parity(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('Compare complete public snapshot', $workflow);
        $this->assertStringContainsString("while IFS=\$'\\t' read -r expected path", $workflow);
        $this->assertStringContainsString('Control-plane marker matches $local_hash; verifying the public edge before trusting it.', $workflow);
        $this->assertStringContainsString('Canonical public edge is incomplete or stale; deployment is required.', $workflow);
        $this->assertStringNotContainsString('remote_hash="$confirmed_hash"', $workflow);
    }

    public function test_static_edge_sync_verifies_every_manifest_file_on_pages_and_the_canonical_cdn(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('foreach (($d["files"] ?? []) as $path => $sha)', $workflow);
        $this->assertStringContainsString('Verify exact Pages deployment and canonical edge', $workflow);
        $this->assertStringContainsString('verify_snapshot "$RUNNER_TEMP/static-edge" "$DEPLOYMENT_URL"', $workflow);
        $this->assertStringContainsString('verify_snapshot "$RUNNER_TEMP/static-edge" "$CDN_URL"', $workflow);
        $this->assertStringContainsString('The canonical CDN did not expose the complete exact deployment within the verification window.', $workflow);
    }

    public function test_static_edge_sync_requires_traffic_gate_origin_and_csp_before_confirmation(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('GATE_URL: https://verify.horusmedia.net', $workflow);
        $this->assertStringContainsString('Verify Traffic Gate origin before confirmation', $workflow);
        $this->assertStringContainsString('$GATE_URL/delivery-manifest.json', $workflow);
        $this->assertStringContainsString('$GATE_URL/assets/traffic-gate/horus-traffic-gate.js', $workflow);
        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $workflow);
        $this->assertStringContainsString("connect-src 'self' https://challenges.cloudflare.com", $workflow);
        $this->assertStringContainsString('Traffic Gate custom origin is stale, incomplete, or missing its enforced Turnstile CSP; refusing production confirmation.', $workflow);

        $gateCheck = strpos($workflow, '- name: Verify Traffic Gate origin before confirmation');
        $confirmation = strpos($workflow, '- name: Confirm manifest on production control plane');
        $this->assertIsInt($gateCheck);
        $this->assertIsInt($confirmation);
        $this->assertLessThan($confirmation, $gateCheck, 'Traffic Gate verification must run before the production confirmation marker is written.');
    }
}
