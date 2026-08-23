<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\GlobalSetting;
use App\Models\StaticGlobalArtifactChange;
use App\Services\Settings\GlobalSettingsService;
use App\Services\Settings\SettingDefinition;
use App\Services\Settings\TypedSettingsRegistry;
use Database\Seeders\SettingsAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class GlobalSettingsGovernanceTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private $admin;
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed(SettingsAccessSeeder::class);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Settings');
        $this->admin = $this->makeUser($horus, RoleName::OperationsAdmin, ['password' => Hash::make('SettingsPass123!')]);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Settings');
        $this->publisher = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
    }

    public function test_registry_is_allowlisted_typed_and_unknown_keys_are_rejected(): void
    {
        $registry = app(TypedSettingsRegistry::class);
        $this->assertArrayHasKey('supply_chain.manager_domain', $registry->all());
        $this->assertSame('domain', $registry->get('supply_chain.manager_domain')->type);
        $this->assertTrue($registry->get('supply_chain.manager_domain')->highImpact);
        $this->expectException(ValidationException::class);
        $registry->get('database.password');
    }

    public function test_type_bounds_domain_email_and_enum_validation_are_server_side(): void
    {
        $registry = app(TypedSettingsRegistry::class);
        $this->assertSame(14, $registry->normalize('supply_chain.ads_txt_fresh_for_days', '14'));
        $this->assertSame('example.com', $registry->normalize('supply_chain.manager_domain', 'HTTPS://Example.COM'));
        $this->assertSame('ops@example.com', $registry->normalize('supply_chain.contact_email', 'OPS@EXAMPLE.COM'));

        foreach ([0, 91] as $invalid) {
            try {
                $registry->normalize('supply_chain.ads_txt_fresh_for_days', $invalid);
                $this->fail('Out-of-range integer was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        try {
            $registry->normalize('supply_chain.manager_domain', 'http://127.0.0.1:8080/path');
            $this->fail('Invalid manager domain was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $enum = new SettingDefinition('test.enum', 'TEST', 'Test enum', 'enum', 'app.env', ['required', 'string'], ['ONE', 'TWO'], 'Test only');
        $this->assertSame('ONE', $registry->normalizeDefinition($enum, 'ONE'));
        $this->expectException(ValidationException::class);
        $registry->normalizeDefinition($enum, 'THREE');
    }

    public function test_database_override_fallback_empty_table_and_cache_invalidation_work(): void
    {
        $settings = app(GlobalSettingsService::class);
        $fallback = config('reporting.retry_delay_minutes');
        $this->assertSame($fallback, $settings->get('reporting.retry_delay_minutes'));

        Cache::put(GlobalSettingsService::CACHE_KEY, ['reporting.retry_delay_minutes' => 999], 300);
        $settings->set($this->admin, 'reporting.retry_delay_minutes', 45, 'Routine reporting policy update');
        $this->assertFalse(Cache::has(GlobalSettingsService::CACHE_KEY));
        $this->assertSame(45, $settings->get('reporting.retry_delay_minutes'));
        $this->assertSame(45, config('reporting.retry_delay_minutes'));

        $settings->reset($this->admin, 'reporting.retry_delay_minutes', 'Return to deployed policy');
        $this->assertSame($fallback, $settings->get('reporting.retry_delay_minutes'));
        $this->assertDatabaseMissing('global_settings', ['key' => 'reporting.retry_delay_minutes']);
    }

    public function test_persisted_override_can_be_applied_to_existing_config_consumers(): void
    {
        GlobalSetting::query()->create(['key' => 'supply_chain.contact_email', 'value' => 'compliance@example.com', 'changed_by' => $this->admin->id]);
        $settings = app(GlobalSettingsService::class);
        $settings->invalidate();
        $settings->applyRuntimeOverrides();
        $this->assertSame('compliance@example.com', config('supply-chain.contact_email'));
    }

    public function test_public_supply_chain_identity_setting_queues_urgent_static_publication(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.settings.update', ['key' => 'supply_chain.contact_email']), [
                'value' => 'mohamed@horusmedia.net',
            ])
            ->assertRedirect();

        $this->assertSame(
            'mohamed@horusmedia.net',
            GlobalSetting::query()->findOrFail('supply_chain.contact_email')->value,
        );
        $change = StaticGlobalArtifactChange::query()->sole();
        $this->assertSame('SUPPLY_CHAIN', $change->artifact_type);
        $this->assertSame('URGENT', $change->priority->value);
        $this->assertSame('SETTING_UPDATED', $change->context['event']);
        $this->assertSame('supply_chain.contact_email', $change->context['setting_key']);
    }

    public function test_production_contact_migration_overrides_legacy_environment_and_queues_publication(): void
    {
        GlobalSetting::query()->whereKey('supply_chain.contact_email')->delete();
        StaticGlobalArtifactChange::query()->delete();

        $migration = require database_path('migrations/2026_08_23_001500_set_public_sellers_contact_email.php');
        $migration->up();

        app(GlobalSettingsService::class)->applyRuntimeOverrides();
        $this->assertSame('mohamed@horusmedia.net', config('supply-chain.contact_email'));
        $this->assertSame(
            'mohamed@horusmedia.net',
            GlobalSetting::query()->findOrFail('supply_chain.contact_email')->value,
        );
        $change = StaticGlobalArtifactChange::query()->sole();
        $this->assertSame('URGENT', $change->priority->value);
        $this->assertSame('PUBLIC_CONTACT_EMAIL_MIGRATED', $change->context['event']);
    }

    public function test_permissions_publisher_denial_audit_and_secret_non_exposure(): void
    {
        $admin = $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $admin->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Settings &amp; Governance', false)
            ->assertSee('supply_chain.manager_domain')
            ->assertDontSee('APP_KEY')
            ->assertDontSee('DB_PASSWORD')
            ->assertDontSee('GAM_HORUS_SERVICE_ACCOUNT_PATH')
            ->assertDontSee('MAIL_PASSWORD');

        $admin->put(route('admin.settings.update', ['key' => 'reporting.retry_delay_minutes']), ['value' => 60])
            ->assertRedirect();
        $this->assertDatabaseHas('global_settings', ['key' => 'reporting.retry_delay_minutes']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.global.updated', 'actor_id' => $this->admin->id]);

        $this->actingAs($this->publisher)->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->publisher)->put('/admin/settings/reporting.retry_delay_minutes', ['value' => 10])->assertForbidden();
    }

    public function test_high_impact_change_requires_reason_password_and_exact_confirmation(): void
    {
        $route = route('admin.settings.update', ['key' => 'supply_chain.manager_domain']);
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put($route, ['value' => 'manager.example.com'])->assertSessionHasErrors(['reason', 'current_password', 'impact_confirmation']);

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put($route, [
                'value' => 'manager.example.com',
                'reason' => 'Approved advertising-system identity migration',
                'current_password' => 'SettingsPass123!',
                'impact_confirmation' => 'CHANGE SUPPLY CHAIN MANAGER DOMAIN',
            ])->assertRedirect();
        $this->assertDatabaseHas('global_settings', ['key' => 'supply_chain.manager_domain']);
    }

    public function test_migration_is_reversible_and_missing_table_falls_back_safely(): void
    {
        $migration = require database_path('migrations/2026_08_10_230000_create_global_settings_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('global_settings'));
        app(GlobalSettingsService::class)->invalidate();
        $this->assertSame(config('reporting.daily_lookback_days'), app(GlobalSettingsService::class)->get('reporting.daily_lookback_days'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('global_settings'));
    }
}
