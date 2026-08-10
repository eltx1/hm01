<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PlacementType;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DemandNetwork;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PlatformControl;
use App\Services\Audit\AuditRecorder;
use App\Services\Operations\ExternalErrorSanitizer;
use App\Services\Operations\PlatformControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class OperationsControlCenterTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_all_configured_control_scopes_validate_real_targets_and_are_audited(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $site = $this->makeSiteFor($publisher, $publisherUser);
        $placement = Placement::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'name' => 'Article Rectangle',
            'code' => 'article-rectangle',
            'type' => PlacementType::Display,
        ]);
        $gam = GamConnection::withoutGlobalScopes()->create([
            'organization_id' => $admin->organization_id,
            'name' => 'Horus Primary',
            'type' => 'HORUS_GAM',
            'credential_type' => 'SERVICE_ACCOUNT',
            'dry_run_default' => true,
        ]);
        $demand = DemandNetwork::create([
            'code' => 'CUSTOM_NATIVE',
            'name' => 'Test Demand',
            'default_integration_mode' => 'DIRECT_JS',
        ]);

        $service = app(PlatformControlService::class);
        $service->set('PLATFORM', null, 'AD_SERVING', true, 'Emergency platform test', $admin);
        $service->set('SITE', $site->id, 'PREBID', true, 'Site prebid test', $admin);
        $service->set('PLACEMENT', $placement->id, 'AD_SERVING', true, 'Placement serving test', $admin);
        $service->set('GAM_CONNECTION', $gam->id, 'AD_SERVING', true, 'GAM serving test', $admin);
        $service->set('DEMAND_NETWORK', $demand->id, 'AD_SERVING', true, 'Demand network test', $admin);

        $this->assertSame(5, PlatformControl::query()->where('is_disabled', true)->count());
        $this->assertSame(5, AuditLog::query()->where('event', 'operations.control.changed')->count());

        $this->expectException(ValidationException::class);
        $service->set('SITE', $site->id, 'NATIVE_NOT_REAL', true, 'Invalid control test', $admin);
    }

    public function test_same_state_control_replay_is_a_no_op(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $service = app(PlatformControlService::class);

        $first = $service->set('PLATFORM', null, 'AD_SERVING', true, 'Emergency serving stop', $admin);
        $firstChangedAt = $first->changed_at;
        $auditCount = AuditLog::query()->count();
        $second = $service->set('PLATFORM', null, 'AD_SERVING', true, 'Duplicate browser submission', $admin);

        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertFalse($second->wasChanged('is_disabled'));
        $this->assertTrue($firstChangedAt->equalTo($second->changed_at));
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_platform_disable_requires_reason_password_and_enhanced_confirmation(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $session = ['two_factor_passed_at' => now()->timestamp];

        $this->actingAs($admin)->withSession($session)->post(route('admin.operations.controls'), [
            'scope_type' => 'PLATFORM',
            'control_key' => 'AD_SERVING',
            'is_disabled' => '1',
            'reason' => 'Emergency serving stop',
            'current_password' => 'password',
        ])->assertSessionHasErrors('impact_confirmation');

        $this->post(route('admin.operations.controls'), [
            'scope_type' => 'PLATFORM',
            'control_key' => 'AD_SERVING',
            'is_disabled' => '1',
            'reason' => 'short',
            'current_password' => 'password',
            'impact_confirmation' => 'DISABLE PLATFORM AD SERVING',
        ])->assertSessionHasErrors('reason');

        $this->post(route('admin.operations.controls'), [
            'scope_type' => 'PLATFORM',
            'control_key' => 'AD_SERVING',
            'is_disabled' => '1',
            'reason' => 'Emergency serving stop',
            'current_password' => 'wrong-password',
            'impact_confirmation' => 'DISABLE PLATFORM AD SERVING',
        ])->assertSessionHasErrors('current_password');
    }

    public function test_operations_and_audit_remain_internal_and_routes_use_web_csrf_group(): void
    {
        $this->seedIdentity();
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);

        $this->actingAs($publisher)->get(route('admin.operations.index'))->assertForbidden();
        $this->get(route('admin.audit.index'))->assertForbidden();

        $controlRoute = Route::getRoutes()->getByName('admin.operations.controls');
        $this->assertNotNull($controlRoute);
        $this->assertContains('POST', $controlRoute->methods());
        $this->assertContains('web', $controlRoute->gatherMiddleware());
    }

    public function test_audit_explorer_filters_and_never_reveals_redacted_secrets(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $audit = app(AuditRecorder::class);
        $audit->record('operations.alpha', $admin->organization_id, $admin, null,
            newValues: ['api_key' => 'super-secret-api-key', 'safe' => 'visible-value'],
            metadata: ['route' => 'admin.operations.controls', 'client_secret' => 'client-secret-value']);
        $audit->record('operations.beta', $admin->organization_id, $admin, null,
            newValues: ['safe' => 'other-value']);

        $response = $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.audit.index', ['event' => 'operations.alpha']));

        $response->assertOk()
            ->assertSee('operations.alpha')
            ->assertDontSee('operations.beta')
            ->assertSee('[REDACTED]')
            ->assertSee('visible-value')
            ->assertDontSee('super-secret-api-key')
            ->assertDontSee('client-secret-value');
    }

    public function test_generic_failed_job_retry_is_not_exposed_and_error_sanitizer_removes_credentials(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('admin.operations.jobs.retry'));

        $sanitized = app(ExternalErrorSanitizer::class)->sanitize(
            'Authorization: Bearer abc123 api_key=secret-value client_secret="very-secret" upstream failed'
        );

        $this->assertStringNotContainsString('abc123', $sanitized);
        $this->assertStringNotContainsString('secret-value', $sanitized);
        $this->assertStringNotContainsString('very-secret', $sanitized);
        $this->assertStringContainsString('[REDACTED]', $sanitized);
    }
}
