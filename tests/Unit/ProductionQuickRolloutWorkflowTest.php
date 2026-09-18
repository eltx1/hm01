<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProductionQuickRolloutWorkflowTest extends TestCase
{
    public function test_lordai_repair_resolves_current_site_by_canonical_domain(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('SITE_DOMAIN: lordai.net', $workflow);
        $this->assertStringContainsString('primary_domain', $workflow);
        $this->assertStringContainsString('Expected exactly one live site for domain', $workflow);
        $this->assertStringContainsString('sticky_bottom', $workflow);
        $this->assertStringContainsString('sticky_top', $workflow);
        $this->assertStringContainsString('lordai-quick-surface-repair-v2.done', $workflow);
        $this->assertStringContainsString('"would_pin_loader_release" => "2.0.0"', $workflow);
        $this->assertStringContainsString('"would_align_anchor_surface_positions"', $workflow);
        $this->assertStringNotContainsString('SITE_KEY: hm_ircll0wkqg54jvt9gz85mhr2', $workflow);
    }

    public function test_automatic_lordai_repair_requires_the_upstream_deploy_job_to_have_completed_successfully(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('Require upstream production deploy job success', $workflow);
        $this->assertStringContainsString("if: github.event_name == 'workflow_run'", $workflow);
        $this->assertStringContainsString('github.rest.actions.listJobsForWorkflowRun', $workflow);
        $this->assertStringContainsString("jobs.filter((job) => job.name === 'deploy')", $workflow);
        $this->assertStringContainsString("deploy.status !== 'completed' || deploy.conclusion !== 'success'", $workflow);
        $this->assertStringContainsString('Upstream deploy job did not complete successfully', $workflow);
    }

    public function test_lordai_remote_tinker_program_cannot_expand_php_variables_as_shell_variables(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString("php artisan tinker --execute='", $workflow);
        $this->assertStringContainsString('getenv("SITE_DOMAIN")', $workflow);
        $this->assertStringContainsString("<<'REMOTE'", $workflow);
        $this->assertStringContainsString('GITHUB_RUN_ID=', $workflow);
        $this->assertStringNotContainsString('tinker --execute="\\$domain=', $workflow);
    }

    public function test_manual_lordai_repair_supports_read_only_dry_run_preview(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('dry_run:', $workflow);
        $this->assertStringContainsString('default: true', $workflow);
        $this->assertStringContainsString("if: github.event_name == 'workflow_dispatch' && inputs.dry_run == true", $workflow);
        $this->assertStringContainsString('Preview LordAI Quick surface reconciliation', $workflow);
        $this->assertStringContainsString('"dry_run" => true', $workflow);
        $this->assertStringContainsString('"would_disable" => $plannedDisabled->all()', $workflow);
        $this->assertStringContainsString("github.event_name == 'workflow_dispatch' && inputs.dry_run == false", $workflow);
    }

    public function test_successful_lordai_repair_dispatches_static_sync_once_and_retries_only_an_unmarked_dispatch(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('Resolve one-time repair state', $workflow);
        $this->assertStringContainsString('id: repair_state', $workflow);
        $this->assertStringContainsString('lordai-quick-surface-repair-v2.static-edge-dispatched', $workflow);
        $this->assertStringContainsString("printf '%s' repair", $workflow);
        $this->assertStringContainsString("printf '%s' dispatch", $workflow);
        $this->assertStringContainsString("printf '%s' complete", $workflow);
        $this->assertStringContainsString("if: steps.repair_state.outputs.state == 'repair'", $workflow);
        $this->assertStringContainsString("if: steps.repair_state.outputs.state == 'repair' || steps.repair_state.outputs.state == 'dispatch'", $workflow);
        $this->assertStringContainsString('Trigger immediate static-edge reconciliation', $workflow);
        $this->assertStringContainsString('gh workflow run sync-production-static-edge.yml', $workflow);
        $this->assertStringContainsString('--ref main', $workflow);
        $this->assertStringContainsString('Mark static-edge reconciliation dispatched', $workflow);
    }

    public function test_lordai_repair_aligns_loader_metadata_and_anchor_geometry_before_urgent_publication(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/lordai-quick-surface-repair.yml');

        $this->assertIsString($workflow);
        $this->assertStringContainsString('$loaderVersion = "2.0.0";', $workflow);
        $this->assertStringContainsString('App\\Models\\LoaderRelease::query()->updateOrCreate', $workflow);
        $this->assertStringContainsString('["loader_release_id" => $loaderRelease->id]', $workflow);
        $this->assertStringContainsString('data_set($settings, "surface.position", $position);', $workflow);
        $this->assertStringContainsString('ops.quick_surface_repair.anchor_geometry_aligned', $workflow);
        $this->assertStringContainsString('ops.quick_surface_repair.loader_release_aligned', $workflow);
        $this->assertStringContainsString('App\\Enums\\StaticDeliveryPriority::Urgent', $workflow);
        $this->assertStringContainsString('data_get($published->payload, "loader.version") !== $loaderVersion', $workflow);
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
