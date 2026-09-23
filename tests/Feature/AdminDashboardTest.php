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

    public function test_admin_navigation_is_grouped_by_operator_tasks_instead_of_system_modules(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $navigation = collect(app(ControlPlaneNavigation::class)->for($admin));
        $groups = $navigation->pluck('label');
        $labels = $navigation->flatMap(fn (array $group) => collect($group['items'])->pluck('label'));

        $this->assertSame([
            'Home',
            'Publishers & Websites',
            'Monetization',
            'Reporting & Finance',
            'Trust & Quality',
            'Operations & Settings',
            'Advertisers',
        ], $groups->all());
        $this->assertTrue($labels->contains('Quick Monetize'));
        $this->assertTrue($labels->contains('Reporting'));
        $this->assertTrue($labels->contains('Finance Operations'));
        $this->assertFalse($groups->contains('Security & Audit'));
        $this->assertFalse($groups->contains('Supply Chain & Compliance'));
    }

    public function test_dashboard_identifies_horus_gam_as_default(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')
            ->assertOk()
            ->assertSee('Total publishers')
            ->assertSee('Recent audit events')
            ->assertSee('Common admin tasks')
            ->assertSee('Quick Monetize')
            ->assertSee('Reporting')
            ->assertSee('Finance')
            ->assertSee('Horus Admin');
    }
}
