<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_security_headers_are_attached_to_web_responses(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('Content-Security-Policy');
    }

    public function test_repeated_failed_logins_lock_the_account_without_extending_an_existing_lock(): void
    {
        config()->set('security.lockout.attempts', 3); config()->set('security.lockout.minutes', 15); $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin, ['password' => 'Correct-Password-123!']);
        foreach (range(1, 3) as $attempt) $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $lockedUntil = $user->fresh()->locked_until; $this->assertNotNull($lockedUntil);
        $this->post('/login', ['email' => $user->email, 'password' => 'Correct-Password-123!'])->assertSessionHasErrors('email');
        $this->assertTrue($lockedUntil->equalTo($user->fresh()->locked_until)); $this->assertGuest();
    }

    public function test_operations_dashboard_is_permission_protected(): void
    {
        $this->seedIdentity();
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $this->actingAs($publisher)->get('/admin/operations')->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/admin/operations')->assertOk()->assertSee('Platform controls')->assertSee('GAM connector kill switches');
    }

    public function test_global_ad_control_is_audited_and_published_for_the_loader(): void
    {
        $root = storage_path('framework/testing/cdn-controls'); File::deleteDirectory($root); config()->set('horus.static_config_root', $root); $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post('/admin/operations/controls', [
            'key' => 'ads_enabled', 'enabled' => '0', 'reason' => 'Production incident test', 'current_password' => 'password',
        ])->assertRedirect();
        $this->assertFalse((bool) data_get(PlatformSetting::findOrFail('ads_enabled')->value, 'enabled'));
        $this->assertFileExists($root.'/control.json');
        $control = json_decode((string) file_get_contents($root.'/control.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($control['adsEnabled']); $this->assertArrayHasKey('checksum', $control); $this->assertDatabaseHas('audit_logs', ['event' => 'operations.control.updated']);
        File::deleteDirectory($root);
    }

    public function test_maintenance_control_blocks_tenants_but_keeps_horus_admin_access(): void
    {
        $this->seedIdentity(); PlatformSetting::create(['key' => 'maintenance_mode', 'value' => ['enabled' => true]]);
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $this->actingAs($publisher)->get('/')->assertStatus(503);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get('/')->assertOk();
    }
}
