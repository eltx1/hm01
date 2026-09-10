<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Services\Demand\DemandAccountService;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DirectDemandAccountWorkspaceTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $horus;
    private $publisherUser;
    private $publisher;
    private $network;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed(DemandNetworkSeeder::class);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Lordai Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, [
            'legal_name' => 'Lordai',
            'display_name' => 'Lordai',
        ]);
        $this->network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
    }

    public function test_create_workspace_distinguishes_horus_publisher_from_provider_identifier(): void
    {
        $this->adminSession()
            ->get(route('admin.demand.accounts.create'))
            ->assertOk()
            ->assertSee('Horus Publisher')
            ->assertSee('Provider account identifier')
            ->assertSee('NOT the Horus Publisher ID')
            ->assertSee($this->publisher->id);
    }

    public function test_publisher_scope_requires_explicit_horus_publisher_assignment(): void
    {
        $this->adminSession()
            ->from(route('admin.demand.accounts.create'))
            ->post(route('admin.demand.accounts.store'), $this->payload([
                'publisher_id' => '',
            ]))
            ->assertRedirect(route('admin.demand.accounts.create'))
            ->assertSessionHasErrors('publisher_id');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
    }

    public function test_manual_publisher_account_accepts_empty_public_configuration_and_blank_origins(): void
    {
        $response = $this->adminSession()->post(route('admin.demand.accounts.store'), $this->payload([
            'approved_script_origins_text' => "\n\n",
            'configuration_json' => '{}',
            'account_identifier' => '',
        ]));

        $account = DemandAccount::withoutGlobalScopes()->latest()->firstOrFail();

        $response->assertRedirect(route('admin.demand.accounts.show', $account));
        $this->assertSame($this->publisher->id, $account->publisher_id);
        $this->assertSame($this->publisher->organization_id, $account->organization_id);
        $this->assertNull($account->account_identifier);
        $this->assertSame([], data_get($account->configuration, 'allowed_script_origins'));
    }

    public function test_manual_google_origin_is_normalized_and_stored_without_duplication(): void
    {
        $this->adminSession()->post(route('admin.demand.accounts.store'), $this->payload([
            'approved_script_origins_text' => "https://securepubads.g.doubleclick.net/\n\nhttps://securepubads.g.doubleclick.net",
            'configuration_json' => '{}',
        ]))->assertRedirect();

        $account = DemandAccount::withoutGlobalScopes()->latest()->firstOrFail();
        $this->assertSame(
            ['https://securepubads.g.doubleclick.net'],
            data_get($account->configuration, 'allowed_script_origins'),
        );
    }

    public function test_non_https_provider_origin_is_rejected(): void
    {
        $this->adminSession()
            ->post(route('admin.demand.accounts.store'), $this->payload([
                'approved_script_origins_text' => 'http://securepubads.g.doubleclick.net',
            ]))
            ->assertSessionHasErrors('approved_script_origins_text');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
    }

    public function test_account_registry_is_compact_and_account_details_have_their_own_workspace(): void
    {
        $account = $this->createAccount();

        $this->adminSession()
            ->get(route('admin.demand.index'))
            ->assertOk()
            ->assertSee('Configured demand accounts')
            ->assertSee($account->name)
            ->assertSee('Open account')
            ->assertDontSee('Credential key');

        $this->get(route('admin.demand.accounts.show', $account))
            ->assertOk()
            ->assertSee('Account identity')
            ->assertSee('Horus Publisher ID')
            ->assertSee($this->publisher->id)
            ->assertSee('Provider account identifier')
            ->assertSee('Not supplied')
            ->assertSee('Parse and review tag');
    }

    public function test_publisher_360_displays_internal_horus_publisher_id(): void
    {
        $this->adminSession()
            ->get(route('admin.publishers.show', $this->publisher))
            ->assertOk()
            ->assertSee('Horus Publisher ID')
            ->assertSee($this->publisher->id);
    }

    private function createAccount(): DemandAccount
    {
        return app(DemandAccountService::class)->create([
            'demand_network_id' => $this->network->id,
            'publisher_id' => $this->publisher->id,
            'name' => 'Google AdX Manual - lordai.net',
            'scope' => DemandAccountScope::Publisher,
            'integration_mode' => DemandIntegrationMode::ManualTag,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 100,
            'account_identifier' => null,
            'configuration' => [
                'allowed_script_origins' => ['https://securepubads.g.doubleclick.net'],
                'render_timeout_ms' => 2500,
            ],
        ], $this->admin)->refresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'demand_network_id' => $this->network->id,
            'name' => 'Google AdX Manual - lordai.net',
            'scope' => 'PUBLISHER',
            'publisher_id' => $this->publisher->id,
            'partner_organization_id' => '',
            'integration_mode' => 'MANUAL_TAG',
            'approval_status' => 'APPROVED',
            'account_identifier' => '',
            'reporting_method' => '',
            'default_render_timeout_ms' => 2500,
            'approved_script_origins_text' => '',
            'revenue_share_percent' => 20,
            'fallback_priority' => 100,
            'configuration_json' => '{}',
            'is_enabled' => 1,
            'is_default' => 0,
        ], $overrides);
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
