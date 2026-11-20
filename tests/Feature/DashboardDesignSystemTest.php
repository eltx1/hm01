<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DashboardDesignSystemTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_shell_uses_svg_icons_breadcrumb_avatar_and_page_heading(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Nile Publishing'), RoleName::PublisherAdmin, ['name' => 'Mona Adel']);
        $this->makePublisherFor($user);

        $html = $this->actingAs($user)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('dashboard'))->assertOk()->getContent();

        // Emoji icons were replaced by one SVG icon family.
        $this->assertStringNotContainsString('🔔', $html);
        $this->assertStringNotContainsString('☰', $html);
        $this->assertStringContainsString('class="ui-icon navigation-icon"', $html);
        $this->assertStringContainsString('class="topbar-icon-button theme-toggle"', $html);
        $this->assertStringContainsString('data-theme-label', $html);
        $this->assertStringContainsString('aria-label="Switch to White Mode"', $html);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('<span aria-current="page">Dashboard</span>', $html);
        $this->assertMatchesRegularExpression('/<div class="page-heading">\s*<h1>Dashboard<\/h1>/', $html);
        $this->assertStringContainsString('<span class="account-avatar" aria-hidden="true">MA</span>', $html);
        $this->assertStringContainsString('<span>My account</span>', $html);
        $this->assertStringContainsString('<span>Sign out</span>', $html);
        // The workspace badge is no longer duplicated next to the title.
        $this->assertStringNotContainsString('<span class="status">Publisher workspace</span>', $html);
        $this->assertStringNotContainsString('A C T I V E', $html);
    }

    public function test_every_navigation_destination_has_an_icon(): void
    {
        $this->seedIdentity();
        foreach ([[OrganizationType::HorusMedia, RoleName::SuperAdmin], [OrganizationType::Publisher, RoleName::PublisherAdmin]] as [$type, $role]) {
            $user = $this->makeUser($this->makeOrganization($type), $role);
            $items = collect(app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for($user))->pluck('items')->flatten(1);

            $this->assertNotEmpty($items);
            $this->assertSame([], $items->where('icon', 'dot')->pluck('label')->all(), 'Navigation items without a dedicated icon.');
        }
    }

    public function test_design_layer_loads_last_and_defines_both_themes(): void
    {
        $entrypoint = File::get(resource_path('js/app.js'));
        $this->assertMatchesRegularExpression("/import '..\\/css\\/workspace-navigation\\.css';\\s*import '..\\/css\\/design-system\\.css';/", $entrypoint);

        $css = File::get(resource_path('css/design-system.css'));
        foreach (['--ds-surface', '--ds-border', ':root[data-hm-theme="light"]', '.navigation-links a.active', '.topbar', '.notification-bell .notification-popover', '.status-badge::before', '@media (max-width: 760px)', '@media (max-width: 1100px)'] as $contract) {
            $this->assertStringContainsString($contract, $css);
        }
        // Brand rule: one theme layer built on brand tokens, no hard-coded off-brand primaries.
        $this->assertStringContainsString('var(--hm-bg-gold)', $css);
        $this->assertStringNotContainsString('#3b82f6', $css);
    }
}
