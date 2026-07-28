<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Advertiser;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AccountManagementTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_horus_admin_can_create_update_and_soft_delete_publisher(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $session = ['two_factor_passed_at' => now()->timestamp];
        $response = $this->actingAs($admin)->withSession($session)->post(route('admin.publishers.store'), $this->publisherData());
        $publisher = Publisher::withoutGlobalScopes()->firstOrFail();
        $response->assertRedirect(route('admin.publishers.edit', $publisher));
        $this->assertSame(OrganizationType::Publisher, $publisher->organization->type);

        $this->withSession($session)->put(route('admin.publishers.update', $publisher), array_merge($this->publisherData(), ['display_name' => 'Updated Publisher']))->assertRedirect();
        $this->assertSame('Updated Publisher', $publisher->fresh()->display_name);
        $this->delete(route('admin.publishers.destroy', $publisher))->assertRedirect(route('admin.publishers.index'));
        $this->assertSoftDeleted('publishers', ['id' => $publisher->id]);
    }

    public function test_horus_admin_can_create_advertiser_and_contact(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.advertisers.store'), [
            'legal_name' => 'Advertiser Inc', 'display_name' => 'Advertiser', 'organization_slug' => 'advertiser', 'status' => 'ACTIVE', 'billing_email' => 'billing@advertiser.test',
        ])->assertRedirect();
        $advertiser = Advertiser::withoutGlobalScopes()->firstOrFail();
        $this->post(route('admin.advertisers.contacts.store', $advertiser), ['name' => 'Billing Contact', 'email' => 'contact@advertiser.test', 'is_primary' => 1])->assertRedirect();
        $this->assertDatabaseHas('advertiser_contacts', ['advertiser_id' => $advertiser->id, 'email' => 'contact@advertiser.test']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'advertiser.contact.created']);
    }

    public function test_horus_admin_can_review_submitted_publisher(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher);
        $publisher = Publisher::withoutGlobalScopes()->create([
            'organization_id' => $publisherOrganization->id, 'legal_name' => 'Review LLC',
            'display_name' => 'Review Publisher', 'status' => 'PENDING', 'onboarding_submitted_at' => now(),
        ]);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.publishers.review', $publisher), ['decision' => 'APPROVE', 'reason' => 'All onboarding data reviewed'])->assertRedirect();

        $this->assertSame('ACTIVE', $publisher->fresh()->status->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher.reviewed', 'auditable_id' => $publisher->id]);
    }

    private function publisherData(): array
    {
        return ['legal_name' => 'Publisher LLC', 'display_name' => 'Publisher', 'organization_slug' => 'publisher', 'status' => 'ACTIVE', 'billing_email' => 'billing@publisher.test', 'primary_color' => '#12499d'];
    }
}
