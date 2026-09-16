<?php

namespace Tests\Feature;

use Tests\TestCase;

class StaticEdgeBootstrapSafetyTest extends TestCase
{
    public function test_bootstrap_cannot_replace_live_static_delivery_on_normal_main_pushes(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/bootstrap-cloudflare-static-edge.yml'));

        $this->assertIsString($workflow);
        $this->assertStringContainsString("  workflow_dispatch:\n", $workflow);
        $this->assertStringNotContainsString("  push:\n", $workflow);
        $this->assertStringContainsString('deploy_baseline:', $workflow);
        $this->assertStringContainsString('echo "created=$created" >> "$GITHUB_OUTPUT"', $workflow);
        $this->assertStringContainsString('Existing Pages project detected. Empty baseline deployment was intentionally skipped', $workflow);

        $guard = "if: steps.pages_project.outputs.created == 'true' || inputs.deploy_baseline == true";
        $this->assertGreaterThanOrEqual(
            5,
            substr_count($workflow, $guard),
            'Every setup/build/deploy/artifact step that can create an empty baseline must be explicitly guarded.',
        );

        $deployStep = strpos($workflow, '- name: Deploy baseline to Cloudflare Pages');
        $guardAfterDeployStep = strpos($workflow, $guard, $deployStep ?: 0);
        $wrangler = strpos($workflow, 'command: pages deploy cloudflare-pages-dist', $deployStep ?: 0);

        $this->assertNotFalse($deployStep);
        $this->assertNotFalse($guardAfterDeployStep);
        $this->assertNotFalse($wrangler);
        $this->assertLessThan($wrangler, $guardAfterDeployStep, 'The baseline deploy guard must appear before the Wrangler deployment command.');
    }

    public function test_production_sync_has_independent_waf_safe_edge_verification(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/sync-production-static-edge.yml'));
        $gateVerifier = file_get_contents(base_path('scripts/verify-traffic-gate-public.sh'));

        $this->assertIsString($workflow);
        $this->assertIsString($gateVerifier);
        $this->assertStringContainsString('scripts/verify-traffic-gate-public.sh', $workflow);
        $this->assertStringContainsString('pinned production host', $workflow);
        $this->assertStringContainsString('$remote_dir/verify-static-edge-public.sh', $workflow);
        $this->assertStringContainsString('$remote_dir/verify-traffic-gate-public.sh', $workflow);
        $this->assertStringContainsString('The canonical CDN did not expose the complete exact deployment from either independent verification network.', $workflow);
        $this->assertStringContainsString('refusing production confirmation.', $workflow);
        $this->assertStringNotContainsString('ssh -o StrictHostKeyChecking=no', $workflow);

        $this->assertStringContainsString('delivery-manifest.json', $gateVerifier);
        $this->assertStringContainsString('horus-traffic-gate.js', $gateVerifier);
        $this->assertStringContainsString("frame-src https://challenges.cloudflare.com", $gateVerifier);
        $this->assertStringContainsString("frame-ancestors https:", $gateVerifier);
        $this->assertStringContainsString('sha256sum', $gateVerifier);
    }
}
