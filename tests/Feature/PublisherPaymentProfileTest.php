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

    public function test_finance_admin_updates_then_verifies_encrypted_payment_profile_with_separate_audit(): void
    {
        $this->seedIdentity();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::FinanceAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.publishers.payment-profile.update', $publisher), [
                'beneficiary_name' => 'Publisher LLC', 'payment_method' => 'BANK_TRANSFER',
                'currency' => 'USD', 'country' => 'US', 'account_reference' => 'PRIVATE-ACCOUNT-9876',
                'routing_reference' => 'PRIVATE-ROUTING', 'tax_identifier' => 'PRIVATE-TAX',
            ])->assertRedirect();

        $profile = PublisherPaymentProfile::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('9876', $profile->account_last_four);
        $this->assertFalse($profile->is_verified);
        $this->assertSame('PENDING_VERIFICATION', $profile->verification_status->value);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.publishers.payment-profile.review', $publisher), [
                'verification_status' => 'VERIFIED',
            ])->assertRedirect();

        $profile->refresh();
        $this->assertTrue($profile->is_verified);
        $this->assertSame('VERIFIED', $profile->verification_status->value);
        $raw = (string) \DB::table('publisher_payment_profiles')->value('payment_details');
        $this->assertStringNotContainsString('PRIVATE-ACCOUNT-9876', $raw);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher.payment_profile.created', 'auditable_id' => $profile->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher.payment_profile.verification_changed', 'auditable_id' => $profile->id]);
    }
}
