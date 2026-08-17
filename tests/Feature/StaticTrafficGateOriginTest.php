<?php

namespace Tests\Feature;

use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class StaticTrafficGateOriginTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_static_snapshot_contains_gate_without_pages_function_or_worker_runtime(): void
    {
        $this->fixture();
        $snapshot = app(StaticDeliverySnapshotBuilder::class)->build();

        $this->assertArrayHasKey('traffic-gate/index.html', $snapshot->files);
        $this->assertArrayHasKey('assets/traffic-gate/horus-traffic-gate.js', $snapshot->files);
        $this->assertArrayNotHasKey('_worker.js', $snapshot->files);
        $this->assertFalse(collect(array_keys($snapshot->files))->contains(
            fn (string $path): bool => $path === 'functions' || str_starts_with($path, 'functions/'),
        ));
        $this->assertSame(
            file_get_contents(public_path('assets/hm-loader.min.js')),
            $snapshot->files['hm-loader.js'],
            'Task 49 must not alter the Horus Loader runtime.',
        );
    }

    public function test_production_gate_has_no_backend_verification_secret_token_transport_or_test_key(): void
    {
        $this->fixture();
        $snapshot = app(StaticDeliverySnapshotBuilder::class)->build();
        $html = $snapshot->files['traffic-gate/index.html'];
        $javascript = $snapshot->files['assets/traffic-gate/horus-traffic-gate.js'];
        $combined = strtolower($html."\n".$javascript);

        $this->assertStringContainsString(
            'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            $javascript,
        );
        $this->assertStringNotContainsString('siteverify', $combined);
        $this->assertStringNotContainsString('turnstile secret', $combined);
        $this->assertStringNotContainsString('cloudflare_api_token', $combined);
        $this->assertStringNotContainsString('TOKEN_MUST_NOT_LEAVE_FRAME', $javascript);
        $this->assertStringNotContainsString('1x00000000000000000000BB', $javascript);
        $this->assertStringNotContainsString('2x00000000000000000000BB', $javascript);
        $this->assertStringContainsString("'response-field': false", $javascript);
        $this->assertStringContainsString('callback: () =>', $javascript);
        $this->assertDoesNotMatchRegularExpression(
            '/postMessage\s*\([^,]+,\s*[\'\"]\*[\'\"]\s*\)/',
            $javascript,
        );
    }

    public function test_gate_headers_are_path_scoped_turnstile_compatible_and_cross_origin_embeddable(): void
    {
        $this->fixture();
        $headers = app(StaticDeliverySnapshotBuilder::class)->build()->files['_headers'];

        $this->assertStringContainsString('/traffic-gate/*', $headers);
        $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $headers);
        $this->assertStringContainsString('frame-src https://challenges.cloudflare.com', $headers);
        $this->assertStringContainsString("connect-src 'self' https://challenges.cloudflare.com", $headers);
        $this->assertStringContainsString('frame-ancestors https:', $headers);
        $this->assertStringContainsString('/assets/traffic-gate/*', $headers);
        $this->assertStringNotContainsString('X-Frame-Options: DENY', $headers);
        $this->assertStringNotContainsString('X-Frame-Options: SAMEORIGIN', $headers);
    }

    private function fixture(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $this->makeGamConnection($horus, $admin, [
            'type' => GamConnectionType::HorusGam,
            'network_code' => '123456789',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherUser = $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher),
            RoleName::PublisherAdmin,
        );
        $this->makeSiteFor(
            $this->makePublisherFor($publisherUser),
            $publisherUser,
            ['primary_domain' => 'publisher.example'],
        );
    }
}
