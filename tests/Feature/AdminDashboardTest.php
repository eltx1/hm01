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

    public function test_admin_dashboard_uses_canonical_usd_even_if_legacy_default_currency_differs(): void
    {
        $this->seedIdentity();
        config([
            'reporting.default_currency' => 'AED',
            'reporting.canonical_currency' => 'USD',
        ]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')
            ->assertOk()
            ->assertSee('Gross revenue · USD')
            ->assertDontSee('Gross revenue · AED');
    }

    public function test_dashboard_identifies_horus_gam_as_default(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')
            ->assertOk()
            ->assertSee('Where do you want to go?')
            ->assertSee('Publishers')
            ->assertSee('Websites')
            ->assertSee('Reports')
            ->assertSee('Total publishers')
            ->assertSee('Recent audit events');
    }
}
