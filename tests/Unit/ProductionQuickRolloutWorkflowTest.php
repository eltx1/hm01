<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionQuickRolloutWorkflowTest extends TestCase
{
    public function test_obsolete_lordai_destructive_repair_workflow_is_removed(): void
    {
        $path = dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml';

        $this->assertFileDoesNotExist(
            $path,
            'The old one-time LordAI repair could disable valid Quick Monetize display surfaces after a deploy.',
        );
    }

    public function test_live_verification_requires_canonical_cdn_runtime_delivery(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/verify-production-live.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('Verify canonical CDN can serve critical ad runtime', $workflow);
        $this->assertStringContainsString('verify_path "hm-loader.js"', $workflow);
        $this->assertStringContainsString('verify_path "configs/_global/control.json"', $workflow);
        $this->assertStringContainsString('verify_path "$LORDAI_CONFIG_PATH"', $workflow);
        $this->assertStringContainsString('Critical ad-serving runtime path $CDN_URL/$relative returned HTTP $status', $workflow);
        $this->assertStringContainsString('Canonical CDN serves the Loader, global controls, LordAI config, and all Horus-owned LordAI Direct JS runtimes byte-for-byte.', $workflow);

        $runtimeStep = strstr($workflow, '- name: Verify canonical CDN can serve critical ad runtime');
        $this->assertIsString($runtimeStep);
        $runtimeStep = strstr($runtimeStep, '- name: Verify representative ads.txt artifacts on exact Pages deployment', true);
        $this->assertIsString($runtimeStep);
        $this->assertStringNotContainsString('HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK', $runtimeStep);
        $this->assertStringNotContainsString("status" == '403'", $runtimeStep);
    }

    public function test_lordai_live_contract_proves_global_controls_and_executable_direct_candidate(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/verify-lordai-in-article-live.php');

        $this->assertIsString($script);
        $this->assertStringContainsString("configs/_global/control.json", $script);
        $this->assertStringContainsString("['adServingDisabled', 'directJsDisabled', 'nativeDemandDisabled']", $script);
        $this->assertStringContainsString("directDemandEnabled", $script);
        $this->assertStringContainsString("quick_in_article_display", $script);
        $this->assertStringContainsString("has no enabled Direct Demand candidate", $script);
        $this->assertStringContainsString("has no executable Direct JS candidate", $script);
        $this->assertStringContainsString("'direct_candidate_count' => $directCandidateCount", $script);
        $this->assertStringContainsString("'global_controls_allow_serving' => true", $script);
    }

    public function test_static_sync_retries_transient_cloudflare_pages_publication_failure_and_stays_fail_closed(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('Deploy live snapshot to Cloudflare Pages · attempt 1', $workflow);
        $this->assertStringContainsString('Deploy live snapshot to Cloudflare Pages · attempt 2', $workflow);
        $this->assertStringContainsString('Deploy live snapshot to Cloudflare Pages · attempt 3', $workflow);
        $this->assertStringContainsString("steps.deploy_attempt_1.outcome != 'success'", $workflow);
        $this->assertStringContainsString("steps.deploy_attempt_2.outcome != 'success'", $workflow);
        $this->assertStringContainsString('Resolve successful Pages deployment', $workflow);
        $this->assertStringContainsString('All Cloudflare Pages deployment attempts failed; refusing to confirm the production manifest.', $workflow);
        $this->assertStringContainsString('DEPLOYMENT_URL: ${{ steps.deploy.outputs.deployment-url }}', $workflow);
    }
}
