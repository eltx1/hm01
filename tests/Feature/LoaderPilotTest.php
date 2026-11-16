<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\ConfigVersion;
use App\Models\LoaderRelease;
use App\Services\Inventory\LoaderArchiveRegistry;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class LoaderPilotTest extends TestCase
{
    use RefreshDatabase, InteractsWithIdentity, InteractsWithPublisherSites;

    public function test_pilot_is_manual_scoped_audited_and_recovers_to_default_without_duplicate_publication(): void
    {
        $this->seedIdentity(); $this->seed(InventoryDeliverySeeder::class);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $owner = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($owner), $owner);
        $site->update(['status' => 'ACTIVE', 'serving_mode' => 'HORUS_DIRECT']);
        $site->siteConfig()->update(['status' => 'ACTIVE']);
        $site->servingSettings()->update(['serving_mode' => 'HORUS_DIRECT']);
        $global = LoaderRelease::where('is_active', true)->pluck('checksum', 'id')->all();
        $digest = array_key_first(app(LoaderArchiveRegistry::class)->records());
        $count = ConfigVersion::withoutGlobalScopes()->count();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->get(route('admin.sites.loader-pilot.show', $site))->assertOk();
        $this->assertSame($count, ConfigVersion::withoutGlobalScopes()->count());
        $data = ['release' => $digest, 'expected_selection' => 'default', 'reason' => 'Controlled internal pilot'];
        $this->put(route('admin.sites.loader-pilot.update', $site), $data)->assertSessionHasNoErrors()->assertRedirect();
        $version = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertStringStartsWith('sha256:', $version->payload['loader']['runtimeBuild']);
        $this->assertSame('sha256-'.base64_encode(hex2bin($digest)), $version->payload['loader']['integrity']);
        $this->assertFalse($site->fresh()->siteConfig->loaderRelease->is_active);
        $this->assertSame($global, LoaderRelease::where('is_active', true)->pluck('checksum', 'id')->all());
        $this->put(route('admin.sites.loader-pilot.update', $site), $data)->assertSessionHasErrors('release');
        $data['expected_selection'] = $digest;
        $this->put(route('admin.sites.loader-pilot.update', $site), $data)->assertSessionHasNoErrors();
        $this->assertSame($count + 1, ConfigVersion::withoutGlobalScopes()->count());
        $data['release'] = 'default';
        $this->put(route('admin.sites.loader-pilot.update', $site), $data)->assertSessionHasNoErrors();
        $this->assertNull($site->fresh()->siteConfig->loader_release_id);
        $site->update(['status' => 'DRAFT']);
        $beforeInactive = ConfigVersion::withoutGlobalScopes()->count();
        $data = ['release' => $digest, 'expected_selection' => 'default', 'reason' => 'Prepare an inactive internal pilot'];
        $this->put(route('admin.sites.loader-pilot.update', $site), $data)->assertSessionHasNoErrors();
        $this->assertSame('DRAFT', $site->fresh()->status->value);
        $this->assertSame($beforeInactive, ConfigVersion::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'site.loader.pilot.changed', 'auditable_id' => $site->id]);
        $this->actingAs($owner)->put(route('admin.sites.loader-pilot.update', $site), $data)->assertForbidden();
    }
}
