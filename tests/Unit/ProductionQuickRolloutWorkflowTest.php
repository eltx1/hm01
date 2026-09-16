<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionQuickRolloutWorkflowTest extends TestCase
{
    public function test_lordai_rollout_resolves_current_site_by_canonical_domain(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-display-suite.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('SITE_DOMAIN: lordai.net', $workflow);
        $this->assertStringContainsString('primary_domain', $workflow);
        $this->assertStringContainsString('Expected exactly one live site for domain', $workflow);
        $this->assertStringContainsString('HORUS_SITE_KEY=hm_', $workflow);
        $this->assertStringContainsString('quick-monetize:clone-display-suite "$site_key"', $workflow);
        $this->assertStringContainsString('lordai-quick-display-suite-v2.done', $workflow);
        $this->assertStringNotContainsString('SITE_KEY: hm_ircll0wkqg54jvt9gz85mhr2', $workflow);
    }

    public function test_lordai_remote_tinker_program_cannot_expand_php_variables_as_shell_variables(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-display-suite.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString("php artisan tinker --execute='", $workflow);
        $this->assertStringContainsString('getenv("SITE_DOMAIN")', $workflow);
        $this->assertStringContainsString("<<'REMOTE'", $workflow);
        $this->assertStringContainsString('GITHUB_RUN_ID=', $workflow);
        $this->assertStringNotContainsString('tinker --execute="\\$domain=', $workflow);
    }

    public function test_successful_lordai_rollout_triggers_immediate_static_sync(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('- LordAI Quick Monetize display suite', $workflow);
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $workflow);
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
