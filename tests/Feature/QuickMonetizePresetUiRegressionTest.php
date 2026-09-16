<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class QuickMonetizePresetUiRegressionTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedIdentity();
        $this->seed(DemandNetworkSeeder::class);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);

        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Quick UI Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'legal_name' => 'Quick UI Publisher',
            'display_name' => 'Quick UI Publisher',
        ]);

        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Quick UI Site',
            'primary_domain' => 'quick-ui.example',
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
        ]);
    }

    public function test_quick_monetize_preserves_real_preset_keys_in_rendered_option_values(): void
    {
        $response = $this->adminSession()->get(route('admin.demand.quick.create'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('value="responsive_display"', $html);
        $this->assertStringContainsString('value="in_article_display"', $html);
        $this->assertStringContainsString('value="sticky_bottom"', $html);
        $this->assertStringContainsString('value="sticky_top"', $html);
        $this->assertStringContainsString('value="video_floating"', $html);

        $this->assertStringNotContainsString('<option value="0"', $html);
        $this->assertStringNotContainsString('<option value="1"', $html);
    }

    public function test_new_placement_mode_hides_and_disables_existing_placement_controls(): void
    {
        $response = $this->adminSession()->get(route('admin.demand.quick.create'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/id="quick-existing-wrap"\s+style="display:none;"/', $html);
        $this->assertMatchesRegularExpression('/name="placement_id"\s+id="quick-placement"\s+disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="placement_preset"\s+id="quick-preset"\s+disabled/', $html);
        $this->assertStringContainsString("existingWrap.style.display = existing ? '' : 'none';", $html);
        $this->assertStringContainsString('placement.disabled = blocked || !existing;', $html);
        $this->assertStringContainsString('preset.disabled = blocked || existing;', $html);
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
