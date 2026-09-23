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

    public function test_admin_navigation_groups_related_workflows_and_exposes_quick_monetize_directly(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $navigation = collect(app(ControlPlaneNavigation::class)->for($admin));

        $this->assertLessThanOrEqual(7, $navigation->count());
        $this->assertTrue($navigation->pluck('label')->contains('Reporting & Finance'));
        $this->assertTrue($navigation->pluck('label')->contains('Quality & Compliance'));
        $monetization = collect($navigation->firstWhere('label', 'Monetization')['items'] ?? []);
        $this->assertSame('Quick Monetize', $monetization->first()['label']);
    }

    public function test_dashboard_identifies_horus_gam_as_default(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')
            ->assertOk()
            ->assertSee('Publishers')
            ->assertSee('Quick Monetize')
            ->assertSee('What needs attention')
            ->assertSee('Recent activity')
            ->assertSee('Horus Admin');
    }
}
