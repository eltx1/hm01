<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class ImpersonationAuditTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_authorized_admin_impersonation_start_and_stop_are_audited(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $target = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherViewer);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->post(route('admin.impersonate.start', $target))->assertRedirect('/');
        $this->assertAuthenticatedAs($target);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.impersonation.started', 'actor_id' => $admin->id]);

        $this->delete(route('admin.impersonate.stop'))->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.impersonation.stopped', 'actor_id' => $admin->id]);
    }
}
