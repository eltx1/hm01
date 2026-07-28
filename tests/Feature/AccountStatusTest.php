<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_horus_admin_can_suspend_publisher_account_with_audit_event(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $publisher = Publisher::withoutGlobalScopes()->create(['organization_id' => $organization->id, 'legal_name' => 'Publisher LLC', 'display_name' => 'Publisher', 'status' => AccountStatus::Active]);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->patch(route('admin.publishers.status', $publisher), ['status' => 'SUSPENDED'])->assertRedirect();

        $this->assertSame(AccountStatus::Suspended, $publisher->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher.status.changed', 'auditable_id' => $publisher->id]);
    }
}
