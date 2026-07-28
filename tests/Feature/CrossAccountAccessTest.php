<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Advertiser;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class CrossAccountAccessTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_publisher_cannot_access_another_publisher(): void
    {
        $this->seedIdentity();
        $one = $this->makeOrganization(OrganizationType::Publisher);
        $two = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($one, RoleName::PublisherAdmin);
        $other = Publisher::withoutGlobalScopes()->create(['organization_id' => $two->id, 'legal_name' => 'Other LLC', 'display_name' => 'Other']);

        $this->actingAs($user)->get(route('account.publisher', $other))->assertNotFound();
    }

    public function test_advertiser_cannot_access_another_advertiser_or_publisher_finance(): void
    {
        $this->seedIdentity();
        $one = $this->makeOrganization(OrganizationType::Advertiser);
        $two = $this->makeOrganization(OrganizationType::Advertiser);
        $user = $this->makeUser($one, RoleName::AdvertiserAdmin);
        $other = Advertiser::withoutGlobalScopes()->create(['organization_id' => $two->id, 'legal_name' => 'Other Inc', 'display_name' => 'Other']);

        $this->actingAs($user)->get(route('account.advertiser', $other))->assertNotFound();
        $this->assertFalse($user->hasPermission('finance.publisher.view'));
        $this->assertFalse($user->hasPermission('finance.internal_margin.view'));
    }

    public function test_publisher_cannot_view_internal_margin_permission(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->assertFalse($user->hasPermission('finance.internal_margin.view'));
    }
}
