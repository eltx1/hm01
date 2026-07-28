<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\PublisherPaymentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherPaymentProfileTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_finance_admin_can_update_encrypted_payment_profile_with_audit(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::FinanceAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.publishers.payment-profile.update', $publisher), [
                'beneficiary_name' => 'Publisher LLC', 'payment_method' => 'BANK_TRANSFER',
                'currency' => 'USD', 'country' => 'US', 'account_reference' => 'PRIVATE-ACCOUNT-9876',
                'routing_reference' => 'PRIVATE-ROUTING', 'tax_identifier' => 'PRIVATE-TAX', 'is_verified' => 1,
            ])->assertRedirect();

        $profile = PublisherPaymentProfile::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('9876', $profile->account_last_four);
        $this->assertTrue($profile->is_verified);
        $raw = (string) \DB::table('publisher_payment_profiles')->value('payment_details');
        $this->assertStringNotContainsString('PRIVATE-ACCOUNT-9876', $raw);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher.payment_profile.updated', 'auditable_id' => $profile->id]);
    }
}
