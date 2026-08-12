<?php

namespace Tests\Feature;

use App\Enums\ServingMode;
use App\Models\Site;
use App\Services\Gam\GamConnectionResolver;
use Tests\TestCase;

class MultiEngineServingArchitectureContractTest extends TestCase
{
    public function test_existing_gam_and_paused_serving_modes_remain_exactly_valid(): void
    {
        $this->assertSame('HORUS_GAM', ServingMode::HorusGam->value);
        $this->assertSame('MCM_PARTNER_GAM', ServingMode::McmPartnerGam->value);
        $this->assertSame('PUBLISHER_GAM', ServingMode::PublisherGam->value);
        $this->assertSame('DIRECT_NATIVE_ONLY', ServingMode::DirectNativeOnly->value);
        $this->assertSame('PAUSED', ServingMode::Paused->value);
    }

    public function test_architecture_can_represent_a_horus_managed_site_without_gam(): void
    {
        $site = new Site([
            'public_key' => 'hm_architecture_contract',
            'serving_mode' => ServingMode::HorusDirect,
        ]);

        $this->assertSame(ServingMode::HorusDirect, $site->serving_mode);
        $this->assertSame('HORUS_DIRECT', $site->serving_mode->value);
        $this->assertNull($site->gam_connection_id);
        $this->assertNull($site->current_gam_network_code);
        $this->assertNull(app(GamConnectionResolver::class)->resolve($site));
    }

    public function test_permanent_loader_tag_is_identical_for_gam_and_no_gam_modes(): void
    {
        config(['horus.loader_url' => 'https://cdn.horusmedia.net/hm-loader.js']);

        $gamSite = new Site([
            'public_key' => 'hm_permanent_loader_contract',
            'serving_mode' => ServingMode::HorusGam,
        ]);
        $directSite = new Site([
            'public_key' => 'hm_permanent_loader_contract',
            'serving_mode' => ServingMode::HorusDirect,
        ]);

        $expected = '<script async src="https://cdn.horusmedia.net/hm-loader.js" data-site-key="hm_permanent_loader_contract"></script>';

        $this->assertSame($expected, $gamSite->installationCode());
        $this->assertSame($expected, $directSite->installationCode());
        $this->assertSame($gamSite->installationCode(), $directSite->installationCode());
    }
}
