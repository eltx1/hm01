<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Mail\HorusNotificationMail;
use App\Models\HorusNotification;
use App\Models\Organization;
use App\Models\Publisher;
use App\Models\User;
use App\Support\Branding\BrandIdentityResolver;
use App\Support\Branding\OfficialBrandAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class BrandFoundationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_owner_approved_assets_are_canonical_complete_and_unchanged(): void
    {
        $assets = app(OfficialBrandAssets::class);

        foreach ($assets->all() as $key => $metadata) {
            $path = public_path($metadata['path']);
            $this->assertFileExists($path, $key);
            $this->assertSame($metadata['sha256'], hash_file('sha256', $path), $key);
            [$width, $height] = getimagesize($path);
            $this->assertSame($metadata['width'], $width, $key);
            $this->assertSame($metadata['height'], $height, $key);
            $this->assertStringContainsString('?v='.substr($metadata['sha256'], 0, 12), $assets->url($key));
        }
    }

    public function test_guest_authentication_shell_uses_official_logo_favicon_and_responsive_structure(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('auth-shell', false)
            ->assertSee('auth-context', false)
            ->assertSee('auth-workspace', false)
            ->assertSee('Horus Media official logo')
            ->assertSee('assets/brand/horusmedia-logo-official.png', false)
            ->assertSee('rel="icon"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('assets/brand/favicon.png', false);

        $css = file_get_contents(resource_path('css/components.css'));
        $this->assertStringContainsString('@media (max-width: 800px)', $css);
        $this->assertStringContainsString('@media (max-width: 430px)', $css);
        $this->assertStringContainsString('grid-template-columns: 1fr', $css);

        $appCss = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('min-width: 320px', $appCss);
        $this->assertStringContainsString('a:focus-visible', $appCss);
    }

    public function test_horus_staff_shell_always_uses_official_identity(): void
    {
        Storage::fake('public');
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Operations');
        Storage::disk('public')->put('branding/horus/unapproved.png', 'not-an-official-logo');
        $organization->update(['logo_path' => 'branding/horus/unapproved.png', 'dashboard_title' => 'Internal Alias']);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-brand-source="horus"', false)
            ->assertSee('assets/brand/horusmedia-emblem.png', false)
            ->assertDontSee('branding/horus/unapproved.png', false)
            ->assertDontSee('Internal Alias')
            ->assertDontSee('--hm-tenant-accent', false)
            ->assertSee('· Horus Media</title>', false)
            ->assertSee('assets/brand/horusmedia-emblem-header.png', false);
    }

    public function test_publisher_workspace_uses_only_its_own_explicit_logo(): void
    {
        Storage::fake('public');
        $this->seedIdentity();
        [$first, $firstUser] = $this->publisherWorkspace('First Publisher', 'branding/first/logo.png');
        [$second, $secondUser] = $this->publisherWorkspace('Second Publisher', 'branding/second/logo.png');

        $this->actingAs($firstUser)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-brand-source="tenant"', false)
            ->assertSee(Storage::disk('public')->url($first->logo_path), false)
            ->assertDontSee(Storage::disk('public')->url($second->logo_path), false)
            ->assertSee('First Publisher Console logo');

        $this->actingAs($secondUser)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(Storage::disk('public')->url($second->logo_path), false)
            ->assertDontSee(Storage::disk('public')->url($first->logo_path), false);
    }

    public function test_tenant_without_custom_logo_gets_horus_fallback_with_tenant_name(): void
    {
        $this->seedIdentity();
        [$organization, $user] = $this->publisherWorkspace('Fallback Publisher');

        $identity = app(BrandIdentityResolver::class)->forWorkspace($user);
        $this->assertFalse($identity->usesTenantLogo);
        $this->assertSame('Fallback Publisher', $identity->name);
        $this->assertSame('Powered by Horus Media', $identity->descriptor);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($organization->name)
            ->assertSee('data-brand-source="horus"', false)
            ->assertSee('assets/brand/horusmedia-emblem.png', false);
    }

    public function test_brand_image_has_accessible_missing_binary_fallback(): void
    {
        $html = Blade::render('<x-brand.image :src="null" alt="Horus Media official logo" fallback="Horus Media" />');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Horus Media official logo"', $html);
        $this->assertStringContainsString('brand-image-fallback', $html);
        $this->assertStringContainsString('Horus Media', $html);
    }

    public function test_transactional_email_renders_reusable_official_brand_shell(): void
    {
        $item = new HorusNotification([
            'category' => NotificationCategory::Account,
            'type' => 'BRAND_SMOKE_TEST',
            'severity' => NotificationSeverity::Info,
            'title' => 'Secure account update',
            'message' => 'Open the Control Plane to review current status.',
        ]);

        $html = (new HorusNotificationMail($item))->render();
        $this->assertStringContainsString('Horus Media official logo', $html);
        $this->assertStringContainsString('assets/brand/horusmedia-logo-official.png', $html);
        $this->assertStringContainsString('Horus Media Control Plane', $html);
        $this->assertStringContainsString('Secure account update', $html);
    }

    public function test_every_rendered_official_asset_url_resolves_to_a_real_public_file(): void
    {
        $assets = app(OfficialBrandAssets::class);

        foreach (array_keys($assets->all()) as $key) {
            $url = $assets->url($key);
            $this->assertNotNull($url);
            $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $this->assertFileExists(public_path($path), $url);
        }
    }

    /** @return array{Organization, User} */
    private function publisherWorkspace(string $name, ?string $logoPath = null): array
    {
        $organization = $this->makeOrganization(OrganizationType::Publisher, $name);
        if ($logoPath) {
            Storage::disk('public')->put($logoPath, 'tenant-logo-'.$name);
            $organization->update(['logo_path' => $logoPath, 'dashboard_title' => $name.' Console']);
        }
        $user = $this->makeUser($organization, RoleName::PublisherAdmin);
        Publisher::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'legal_name' => $name.' LLC',
            'display_name' => $name,
            'status' => AccountStatus::Active,
        ]);

        return [$organization, $user];
    }
}
