<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Operations\PlatformControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_applied(): void
    {
        $this->get('/login')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_account_is_locked_after_configured_failed_attempts(): void
    {
        config(['security.authentication.max_failed_attempts' => 2, 'security.authentication.lock_minutes' => 10]);
        $organization = Organization::create(['name' => 'Test', 'slug' => 'test-'.str()->random(8), 'type' => OrganizationType::Publisher, 'status' => AccountStatus::Active]);
        $user = User::factory()->for($organization)->create(['email' => 'locked@example.com', 'password' => Hash::make('Correct!Password123'), 'status' => UserStatus::Active]);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $this->assertTrue($user->fresh()->isLocked());
        $this->post('/login', ['email' => $user->email, 'password' => 'Correct!Password123'])->assertSessionHasErrors('email');
    }

    public function test_platform_control_is_cached_and_audited(): void
    {
        $organization = Organization::create(['name' => 'Test', 'slug' => 'test-'.str()->random(8), 'type' => OrganizationType::Publisher, 'status' => AccountStatus::Active]);
        $user = User::factory()->for($organization)->create(['status' => UserStatus::Active]);
        config(['horus.static_config_root' => storage_path('framework/testing/production-controls')]);
        $service = app(PlatformControlService::class);
        $service->set('PLATFORM', null, 'AD_SERVING', true, 'Emergency production test', $user);
        $this->assertTrue($service->disabled('PLATFORM', null, 'AD_SERVING'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'operations.control.changed']);
    }
}
