<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\ConfigVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class ConfigurationHistoryTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_history_is_read_only_scoped_and_does_not_render_provider_payloads(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($owner), $owner);
        foreach ([1, 2] as $number) ConfigVersion::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id, 'site_id' => $site->id, 'site_config_id' => $site->siteConfig->id,
            'environment' => 'PRODUCTION', 'version' => $number, 'status' => $number === 1 ? 'DEPLOYED' : 'PENDING_DELIVERY',
            'payload' => ['status' => 'active', 'clickGuard' => ['enabled' => $number === 1], 'provider' => 'PRIVATE_PROVIDER_TAG', 'trafficGate' => ['siteKey' => 'PRIVATE_KEY']],
            'checksum' => str_repeat('a', 64), 'file_path' => 'configs/test'.$number.'.json', 'created_by' => $admin->id,
        ]);
        $site->siteConfig->update(['production_version' => 1]);
        $before = ConfigVersion::withoutGlobalScopes()->get()->toJson();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.config.history', $site))->assertOk()->assertSee('Confirmed production version: 1')
            ->assertSee('PENDING_DELIVERY')->assertSee('Different')->assertDontSee('PRIVATE_');
        $this->assertSame($before, ConfigVersion::withoutGlobalScopes()->get()->toJson());
        $this->actingAs($owner)->get(route('admin.sites.config.history', $site))->assertForbidden();
    }

    public function test_projection_preserves_unknown_and_never_invents_a_baseline(): void
    {
        $projection = new \App\Services\Sites\ConfigurationHistoryProjection;
        $result = $projection->changes(['status' => 'PRIVATE_STATE', 'trafficGate' => ['enabled' => 'PRIVATE_VALUE']], null);
        $this->assertFalse($result['baseline_available']);
        $this->assertNull($result['fields']['Cloudflare enabled']);
        $this->assertNull($result['fields']['Placement count']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($result));
    }
}
