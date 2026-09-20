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
        $this->assertStringContainsString('value="rewarded"', $html);

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
        $this->assertStringContainsString('VAST URL or provider-issued ad tag', $html);
        $this->assertStringContainsString('Horus video player', $html);
        $this->assertStringContainsString('Complete provider code keeps its provider-managed lifecycle', $html);
        $this->assertStringContainsString('/NetworkCode/AdUnitCode', $html);
        $this->assertStringContainsString('Choose Rewarded Video for an explicit opt-in flow', $html);
        $this->assertStringContainsString("preset.value = 'video_floating';", $html);
    }

    public function test_quick_form_offers_full_provider_code_or_gam_path_with_the_selected_surface_type(): void
    {
        $response = $this->adminSession()->get(route('admin.demand.quick.create'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, preg_match('/<select[^>]*name="tag_input_type"[^>]*>(.*?)<\/select>/s', $html, $matches));
        $this->assertSame(2, substr_count($matches[1], '<option '));
        $this->assertStringContainsString('value="PROVIDER_TAG"', $matches[1]);
        $this->assertStringContainsString('value="GAM_AD_UNIT_PATH"', $matches[1]);
        $this->assertStringContainsString('Full provider tag · GPT or another provider', $matches[1]);
        $this->assertStringContainsString('/Network_Code/Adunit_Code', $matches[1]);
        foreach (['responsive_display' => 'DISPLAY', 'sticky_top' => 'STICKY', 'sticky_bottom' => 'STICKY', 'rewarded' => 'REWARDED', 'video_floating' => 'VIDEO'] as $preset => $type) {
            $this->assertMatchesRegularExpression('/<option value="'.$preset.'"[^>]*data-placement-type="'.$type.'"/', $html);
        }
        $this->assertDoesNotMatchRegularExpression('/preset\.value\s*=\s*[\'"]rewarded[\'"]/', $html);
    }

    public function test_redisplaying_path_input_keeps_the_selected_responsive_surface_and_input_mode(): void
    {
        $response = $this->adminSession()->withSession(['_old_input' => [
            'placement_mode' => 'new',
            'placement_preset' => 'responsive_display',
            'tag_input_type' => 'GAM_AD_UNIT_PATH',
            'tag' => '/1234567/responsive',
        ]])->get(route('admin.demand.quick.create'))->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<option value="GAM_AD_UNIT_PATH"[^>]*\sselected[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/<option value="responsive_display"[^>]*\sselected[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="rewarded"[^>]*\sselected[^>]*>/', $html);
        $response->assertSee('/1234567/responsive');
        $this->assertDoesNotMatchRegularExpression('/preset\.value\s*=\s*[\'"]rewarded[\'"]/', $html);
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
