<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    public function test_dashboard_identifies_horus_gam_as_default(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('HORUS_GAM')
            ->assertSee('Default');
    }
}
