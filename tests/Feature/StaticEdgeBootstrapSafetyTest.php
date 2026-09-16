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

    public function test_production_sync_has_guarded_waf_safe_edge_verification(): void
    {
        $workflow = file_get_contents(base_path('.github/workflows/sync-production-static-edge.yml'));
        $staticVerifier = file_get_contents(base_path('scripts/verify-static-edge-public.sh'));
        $gateVerifier = file_get_contents(base_path('scripts/verify-traffic-gate-public.sh'));
        $domainVerifier = file_get_contents(base_path('scripts/verify-cloudflare-pages-domain-active.sh'));

        $this->assertIsString($workflow);
        $this->assertIsString($staticVerifier);
        $this->assertIsString($gateVerifier);
        $this->assertIsString($domainVerifier);

        $this->assertStringContainsString('CLOUDFLARE_PAGES_PROJECT:', $workflow);
        $this->assertStringContainsString('pinned production host', $workflow);
        $this->assertStringContainsString('$remote_dir/verify-static-edge-public.sh', $workflow);
        $this->assertStringContainsString('$remote_dir/verify-traffic-gate-public.sh', $workflow);
        $this->assertStringContainsString('HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK=1', $workflow);
        $this->assertStringContainsString('gate_waf_fallback=1', $workflow);
        $this->assertStringContainsString('no guarded WAF 403 proof was available', $workflow);
        $this->assertStringContainsString('blocked without a guarded WAF proof', $workflow);
        $this->assertStringNotContainsString('ssh -o StrictHostKeyChecking=no', $workflow);

        $this->assertStringContainsString('HTTP ${remote_status:-000}', $staticVerifier);
        $this->assertStringContainsString('HTTP ${manifest_status:-000}', $gateVerifier);
        $this->assertStringContainsString('verify-cloudflare-pages-domain-active.sh', $staticVerifier);
        $this->assertStringContainsString('verify-cloudflare-pages-domain-active.sh', $gateVerifier);
        $this->assertStringContainsString('/pages/projects/$project/domains', $domainVerifier);
        $this->assertStringContainsString('.name == $domain and .status == "active"', $domainVerifier);

        $this->assertStringContainsString('delivery-manifest.json', $gateVerifier);
        $this->assertStringContainsString('horus-traffic-gate.js', $gateVerifier);
        $this->assertStringContainsString("frame-src https://challenges.cloudflare.com", $gateVerifier);
        $this->assertStringContainsString("frame-ancestors https:", $gateVerifier);
        $this->assertStringContainsString('sha256sum', $gateVerifier);
    }
}
