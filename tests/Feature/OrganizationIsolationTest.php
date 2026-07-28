<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class OrganizationIsolationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_publisher_queries_are_automatically_scoped_to_authenticated_organization(): void
    {
        $this->seedIdentity();
        $one = $this->makeOrganization(OrganizationType::Publisher, 'Publisher One');
        $two = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Two');
        Publisher::withoutGlobalScopes()->create(['organization_id' => $one->id, 'legal_name' => 'One LLC', 'display_name' => 'One']);
        Publisher::withoutGlobalScopes()->create(['organization_id' => $two->id, 'legal_name' => 'Two LLC', 'display_name' => 'Two']);
        $user = $this->makeUser($one, RoleName::PublisherAdmin);

        $this->actingAs($user);
        $this->assertSame(1, Publisher::query()->count());
        $this->assertSame($one->id, Publisher::firstOrFail()->organization_id);
    }

    public function test_horus_admin_can_operate_across_organizations(): void
    {
        $this->seedIdentity();
        foreach (['One', 'Two'] as $name) {
            $organization = $this->makeOrganization(OrganizationType::Publisher, $name);
            Publisher::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'legal_name' => "$name LLC", 'display_name' => $name]);
        }
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin);
        $this->assertSame(2, Publisher::query()->count());
    }
}
