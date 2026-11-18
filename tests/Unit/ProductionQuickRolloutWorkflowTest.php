<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionQuickRolloutWorkflowTest extends TestCase
{
    public function test_destructive_one_time_lordai_repair_can_no_longer_be_triggered_after_deployment(): void
    {
        // The prior workflow ran automatically on successful production deploys and
        // disabled active Direct/Quick placements. Restoration must be a reviewed,
        // separately authorized operation, never a recurrent deployment hook.
        $this->assertFileDoesNotExist(
            dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml'
        );
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
