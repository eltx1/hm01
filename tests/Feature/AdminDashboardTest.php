<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
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

        $response = $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/');
        $response->assertOk()
            ->assertSee('See what needs attention, then act.')
            ->assertSee('Common workflows')
            ->assertSee('Publishers')
            ->assertSee('Websites')
            ->assertSee('Reporting & Revenue')
            ->assertSee('Recent audit activity');

        $groups = collect(app(\App\Services\ControlPlane\ControlPlaneNavigation::class)->for($admin));
        $this->assertSame(
            ['Home', 'Publishers & Sites', 'Monetization', 'Reporting & Finance', 'Trust & Operations', 'Platform'],
            $groups->pluck('label')->all(),
        );
        $this->assertLessThanOrEqual(6, $groups->count());
    }
}
