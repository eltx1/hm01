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
        $this->assertStringContainsString('Control-plane marker matches $local_hash; verifying the public edge before trusting it.', $workflow);
        $this->assertStringContainsString('Canonical public edge is incomplete or stale; deployment is required.', $workflow);
        $this->assertStringContainsString('scripts/verify-static-edge-public.sh "$RUNNER_TEMP/static-edge" "$CDN_URL" compare 32', $workflow);
        $this->assertStringNotContainsString('remote_hash="$confirmed_hash"', $workflow);
    }

    public function test_public_parity_verifier_includes_root_manifest_and_uses_bounded_parallelism(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/verify-static-edge-public.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('$base/delivery-manifest.json?edge_verify=', $script);
        $this->assertStringContainsString('cmp -s "$manifest" "$remote_manifest"', $script);
        $this->assertStringContainsString('xargs -0 -n2 -P "$concurrency"', $script);
        $this->assertStringContainsString('concurrency="${4:-32}"', $script);
        $this->assertStringContainsString('concurrency <= 64', $script);
        $this->assertStringContainsString('| sha256sum | cut -d " " -f1', $script);
    }

    public function test_static_edge_sync_verifies_exact_pages_before_guarded_custom_domain_fallback(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/verify-static-edge-public.sh');
        $domainVerifier = file_get_contents(dirname(__DIR__, 2).'/scripts/verify-cloudflare-pages-domain-active.sh');

        $this->assertIsString($workflow);
        $this->assertIsString($script);
        $this->assertIsString($domainVerifier);
        $this->assertStringContainsString('Verify exact Pages deployment and canonical edge', $workflow);
        $this->assertStringContainsString('scripts/verify-static-edge-public.sh "$RUNNER_TEMP/static-edge" "$DEPLOYMENT_URL" "pages-$attempt" 32', $workflow);
        $this->assertStringContainsString('HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK=1', $workflow);
        $this->assertStringContainsString('scripts/verify-static-edge-public.sh "$RUNNER_TEMP/static-edge" "$CDN_URL" "cdn-$attempt" 32', $workflow);
        $this->assertStringContainsString('no guarded WAF 403 proof was available', $workflow);
        $this->assertStringContainsString('bash -n scripts/verify-cloudflare-pages-domain-active.sh', $workflow);
        $this->assertStringContainsString('timeout-minutes: 30', $workflow);

        $pagesCheck = strpos($workflow, 'scripts/verify-static-edge-public.sh "$RUNNER_TEMP/static-edge" "$DEPLOYMENT_URL" "pages-$attempt" 32');
        $guardedCdnCheck = strpos($workflow, 'HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK=1');
        $this->assertIsInt($pagesCheck);
        $this->assertIsInt($guardedCdnCheck);
        $this->assertLessThan($guardedCdnCheck, $pagesCheck, 'Exact Pages parity must be proven before the custom-domain 403 fallback can be enabled.');

        $this->assertStringContainsString('[[ "$remote_status" == \'403\' && "$waf_fallback" == \'1\' ]]', $script);
        $this->assertStringContainsString('verify-cloudflare-pages-domain-active.sh', $script);
        $this->assertStringContainsString('/pages/projects/$project/domains', $domainVerifier);
        $this->assertStringContainsString('.status == "active"', $domainVerifier);
    }

    public function test_static_edge_sync_requires_traffic_gate_csp_before_confirmation(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/sync-production-static-edge.yml');
        $gateVerifier = file_get_contents(dirname(__DIR__, 2).'/scripts/verify-traffic-gate-public.sh');

        $this->assertIsString($workflow);
        $this->assertIsString($gateVerifier);
        $this->assertStringContainsString('GATE_URL: https://verify.horusmedia.net', $workflow);
        $this->assertStringContainsString('Verify Traffic Gate origin before confirmation', $workflow);
        $this->assertStringContainsString('scripts/verify-traffic-gate-public.sh "$RUNNER_TEMP/static-edge" "$DEPLOYMENT_URL" gate-pages', $workflow);
        $this->assertStringContainsString('HORUS_ALLOW_CLOUDFLARE_WAF_403_FALLBACK="$gate_waf_fallback"', $workflow);
        $this->assertStringContainsString('scripts/verify-traffic-gate-public.sh "$RUNNER_TEMP/static-edge" "$GATE_URL"', $workflow);
        $this->assertStringContainsString('blocked without a guarded WAF proof', $workflow);

        $this->assertStringContainsString('$base/delivery-manifest.json?edge_verify=', $gateVerifier);
        $this->assertStringContainsString('[[ "$manifest_status" == \'403\' && "$waf_fallback" == \'1\' ]]', $gateVerifier);
        $this->assertStringContainsString('$base/assets/traffic-gate/horus-traffic-gate.js?edge_verify=', $gateVerifier);
        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $gateVerifier);
        $this->assertStringContainsString("frame-src https://challenges.cloudflare.com", $gateVerifier);
        $this->assertStringContainsString("connect-src 'self' https://challenges.cloudflare.com", $gateVerifier);
        $this->assertStringContainsString("frame-ancestors https:", $gateVerifier);

        $gateCheck = strpos($workflow, '- name: Verify Traffic Gate origin before confirmation');
        $confirmation = strpos($workflow, '- name: Confirm manifest on production control plane');
        $this->assertIsInt($gateCheck);
        $this->assertIsInt($confirmation);
        $this->assertLessThan($confirmation, $gateCheck, 'Traffic Gate verification must run before the production confirmation marker is written.');
    }
}
