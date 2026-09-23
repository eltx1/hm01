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

    public function test_dashboard_identifies_horus_gam_as_default(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')
            ->assertOk()
            ->assertSee('Run the platform from one starting point.')
            ->assertSee('Common workflows')
            ->assertSee('Quick Monetize')
            ->assertSee('Reporting &amp; finance', false)
            ->assertSee('Recent audit events');

        $groups = collect(app(ControlPlaneNavigation::class)->for($admin))->pluck('label')->all();
        $this->assertSame([
            'Home',
            'Publishers & Sites',
            'Monetization',
            'Reporting & Finance',
            'Quality & Compliance',
            'Operations & Access',
        ], $groups);
    }
}
