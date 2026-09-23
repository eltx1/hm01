<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\ControlPlane\ControlPlaneNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_admin_navigation_prioritizes_daily_work_and_collapses_advanced_tools(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $navigation = collect(app(ControlPlaneNavigation::class)->for($admin));

        $this->assertSame(['Workspace', 'Revenue', 'Monetization', 'More tools'], $navigation->pluck('label')->all());
        $this->assertSame(
            ['Home', 'Publishers', 'Websites', 'Quick Monetize'],
            collect($navigation->firstWhere('label', 'Workspace')['items'])->pluck('label')->all(),
        );
        $this->assertSame(
            ['Reports', 'Finance'],
            collect($navigation->firstWhere('label', 'Revenue')['items'])->pluck('label')->all(),
        );
        $this->assertTrue((bool) $navigation->firstWhere('label', 'More tools')['collapsible']);
    }

    public function test_dashboard_identifies_horus_gam_as_default(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')
            ->assertOk()
            ->assertSee('Run the network from one place.')
            ->assertSee('Publishers')
            ->assertSee('Websites')
            ->assertSee('Quick Monetize')
            ->assertSee('Reporting currency')
            ->assertSee('USD')
            ->assertSee('Recent activity');
    }
}
