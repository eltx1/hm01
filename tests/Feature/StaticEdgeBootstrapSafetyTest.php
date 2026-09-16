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
}
