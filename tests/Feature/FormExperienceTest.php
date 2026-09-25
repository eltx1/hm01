<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Services\Reporting\PublisherPaymentProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class FormExperienceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Example Publishing');
        $user = $this->makeUser($organization, RoleName::PublisherAdmin, ['name' => 'Alex Publisher', 'email' => 'alex@example.test']);
        $publisher = $this->makePublisherFor($user);
        $finance = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::FinanceAdmin);

        return [$user, $publisher, $finance];
    }

    private function details(): array
    {
        return ['beneficiary_name' => 'Example Publishing', 'payment_method' => 'BANK_TRANSFER',
            'currency' => 'USD', 'country' => 'US', 'account_reference' => 'PRIVATE-ACCOUNT-9876',
            'routing_reference' => 'PRIVATE-SWIFT', 'tax_identifier' => 'PRIVATE-TAX'];
    }

    public function test_routing_only_update_preserves_account_and_resets_verification_without_exposing_secrets(): void
    {
        [$user, $publisher, $finance] = $this->context();
        $service = app(PublisherPaymentProfileService::class);
        $profile = $service->save($publisher, $this->details(), $user);
        $service->review($profile, PublisherPaymentProfileStatus::Verified, $finance);
        $data = array_replace($this->details(), ['account_reference' => '', 'routing_reference' => 'NEW-SWIFT', 'tax_identifier' => '']);
        $this->actingAs($user)->put(route('publisher.finance.payment-method.update'), $data)->assertRedirect()->assertSessionHasNoErrors();
        $profile->refresh();
        $this->assertSame('PRIVATE-ACCOUNT-9876', $profile->payment_details['account_reference']);
        $this->assertSame('NEW-SWIFT', $profile->payment_details['routing_reference']);
        $this->assertSame('9876', $profile->account_last_four);
        $this->assertSame('PRIVATE-TAX', $profile->tax_identifier);
        $this->assertSame(PublisherPaymentProfileStatus::NeedsUpdate, $profile->verification_status);
        $this->assertFalse($profile->is_verified);
        $this->assertStringNotContainsString('NEW-SWIFT', DB::table('publisher_payment_profiles')->value('payment_details'));
        $this->assertStringNotContainsString('NEW-SWIFT', AuditLog::query()->get()->toJson());
    }

    public function test_method_change_requires_its_destination_and_blank_same_method_keeps_verified_details(): void
    {
        [$user, $publisher, $finance] = $this->context();
        $service = app(PublisherPaymentProfileService::class);
        $profile = $service->save($publisher, $this->details(), $user);
        $service->review($profile, PublisherPaymentProfileStatus::Verified, $finance);
        $data = array_replace($this->details(), ['account_reference' => '', 'routing_reference' => '', 'tax_identifier' => '']);
        $this->actingAs($user)->put(route('publisher.finance.payment-method.update'), $data)->assertSessionHasNoErrors();
        $this->assertSame(PublisherPaymentProfileStatus::Verified, $profile->fresh()->verification_status);
        $this->put(route('publisher.finance.payment-method.update'), array_replace($data, ['payment_method' => 'PAYPAL']))
            ->assertSessionHasErrors('account_reference');
        $this->assertSame('BANK_TRANSFER', $profile->fresh()->payment_method);
        $this->assertSame(PublisherPaymentProfileStatus::Verified, $profile->fresh()->verification_status);
        $this->put(route('publisher.finance.payment-method.update'), array_replace($data, ['payment_method' => 'PAYPAL', 'account_reference' => 'billing@example.test']))->assertRedirect();
        $profile->refresh();
        $this->assertSame('PAYPAL', $profile->payment_method);
        $this->assertSame('billing@example.test', $profile->payment_details['account_reference']);
        $this->assertNull($profile->payment_details['routing_reference']);
        $this->assertSame(PublisherPaymentProfileStatus::NeedsUpdate, $profile->verification_status);
    }

    public function test_real_pages_render_accessible_forms_and_permission_safe_payment_states(): void
    {
        [$user, $publisher, $finance] = $this->context();
        $this->actingAs($user);
        $paymentRoute = route('publisher.finance.payment-method.edit');
        $this->withoutExceptionHandling();
        $this->fixture('payment-empty', $this->get($paymentRoute)->assertOk()->assertSee('Choose how you get paid')->assertSee('aria-current="page"', false));
        $this->withExceptionHandling();
        $service = app(PublisherPaymentProfileService::class);
        $profile = $service->save($publisher, $this->details(), $user);
        $service->review($profile, PublisherPaymentProfileStatus::Verified, $finance);
        $this->fixture('payment-verified', $this->get($paymentRoute)->assertOk()->assertSee('••••9876')->assertDontSee('PRIVATE-ACCOUNT')->assertDontSee('PRIVATE-SWIFT')->assertDontSee('PRIVATE-TAX'));
        $this->from($paymentRoute)->put(route('publisher.finance.payment-method.update'), array_replace($this->details(), ['country' => 'INVALID']))->assertSessionHasErrors('country');
        $this->fixture('payment-error', $this->get($paymentRoute)->assertOk()->assertSee('aria-invalid="true"', false)->assertDontSee('PRIVATE-ACCOUNT'));
        session()->forget(['errors', '_old_input']);
        $site = $this->makeSiteFor($publisher, $user, ['primary_domain' => 'example.test', 'display_name' => 'Example website']);
        foreach (['site-create' => route('publisher.sites.create'), 'site-edit' => route('publisher.sites.edit', $site),
            'support-create' => route('support.tickets.create'), 'account-profile' => route('account.profile.edit'),
            'account-branding' => route('account.branding.edit'), 'account-security' => route('account.security')] as $name => $url) {
            $this->fixture($name, $this->get($url)->assertOk()->assertSee('ui-page', false));
        }
        $viewer = $this->makeUser($user->organization, RoleName::PublisherViewer);
        $this->actingAs($viewer);
        $this->fixture('payment-viewer', $this->get($paymentRoute)->assertOk()->assertDontSee('data-payment-profile-form')->assertDontSee('Save payment method')->assertSee('••••9876'));
        $this->put(route('publisher.finance.payment-method.update'), $this->details())->assertForbidden();
        $this->actingAs($finance)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->fixture('admin-payment', $this->get(route('admin.publishers.payment-profile.edit', $publisher))->assertOk()->assertSee('Record verification decision')->assertDontSee('PRIVATE-ACCOUNT'));
    }

    private function fixture(string $name, \Illuminate\Testing\TestResponse $response): void
    {
        // Browser QA consumes real HTTP-rendered Blade, never a second mock layout.
        if (getenv('HORUS_UI_FIXTURES') !== '1') {
            return;
        }
        $directory = storage_path('framework/testing/form-experience');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
