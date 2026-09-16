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
}
