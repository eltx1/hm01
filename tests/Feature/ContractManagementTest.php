<?php

namespace Tests\Feature;

use App\Enums\ContractStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\RevenueRuleScope;
use App\Models\PublisherContract;
use App\Models\RevenueRule;
use App\Services\Contracts\ContractLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class ContractManagementTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_contract_status_transitions_are_enforced_and_audited(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $contract = $this->contract($publisher->id, $publisher->organization_id, $admin->id);
        $service = app(ContractLifecycleService::class);

        $contract->update(['revenue_share_percent' => 80]);
        $service->transition($contract, ContractStatus::Active, $admin, 'Approved commercial terms');
        $rule = RevenueRule::withoutGlobalScopes()
            ->where('scope_type', RevenueRuleScope::Publisher->value)
            ->where('scope_id', $publisher->id)
            ->firstOrFail();
        $this->assertSame(8000, (int) $rule->currentVersion->publisher_share_bp);
        $this->assertSame(2000, (int) $rule->currentVersion->horus_share_bp);

        $service->transition($contract, ContractStatus::Expired, $admin, 'Terms ended');
        $this->assertSame(ContractStatus::Expired, $contract->fresh()->status);
        $this->assertFalse($rule->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher_contract.status.changed', 'auditable_id' => $contract->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'reporting.revenue_rule.created', 'auditable_id' => $rule->id]);

        $this->expectException(ValidationException::class);
        $service->transition($contract, ContractStatus::Active, $admin);
    }

    public function test_contract_upload_uses_private_storage_and_other_publisher_cannot_download(): void
    {
        Storage::fake('local');
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser);
        $contract = $this->contract($publisher->id, $publisher->organization_id, $admin->id);

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.publishers.contracts.upload', [$publisher, $contract]), ['contract_file' => UploadedFile::fake()->create('agreement.pdf', 20, 'application/pdf')])->assertRedirect();
        Storage::disk('local')->assertExists($contract->fresh()->contract_file_path);

        $other = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->makePublisherFor($other, ['legal_name' => 'Other LLC', 'display_name' => 'Other']);
        $this->actingAs($other)->get(route('publisher.contracts.download', $contract))->assertNotFound();
    }

    private function contract(string $publisherId, string $organizationId, string $creatorId): PublisherContract
    {
        return PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $organizationId, 'publisher_id' => $publisherId,
            'contract_reference' => 'HM-001', 'revenue_share_percent' => 70,
            'payment_threshold' => 100, 'currency' => 'USD', 'payment_terms' => 'NET_30',
            'status' => ContractStatus::Draft, 'created_by' => $creatorId,
        ]);
    }
}
