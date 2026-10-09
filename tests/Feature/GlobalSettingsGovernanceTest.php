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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class GlobalSettingsGovernanceTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private $admin;
    private $adOps;
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed(SettingsAccessSeeder::class);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Settings');
        $this->admin = $this->makeUser($horus, RoleName::OperationsAdmin, ['password' => Hash::make('SettingsPass123!')]);
        $this->adOps = $this->makeUser($horus, RoleName::AdOpsAdmin, ['password' => Hash::make('AdOpsPass123!')]);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Settings');
        $this->publisher = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
    }

    public function test_registry_is_allowlisted_typed_and_unknown_keys_are_rejected(): void
    {
        $registry = app(TypedSettingsRegistry::class);
        $this->assertArrayHasKey('supply_chain.manager_domain', $registry->all());
        $this->assertArrayHasKey('video_player.content_url', $registry->all());
        $this->assertSame('url', $registry->get('video_player.content_url')->type);
        $this->assertArrayNotHasKey('video_player.inventory_type', $registry->all());
        $this->assertSame(['muted', 'prefer_audible'], $registry->get('video_player.autoplay_audio')->allowedValues);
        $this->assertSame('integer', $registry->get('video_player.mid_roll_interval_seconds')->type);
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
        $this->assertSame(
            'https://cdn.horusmedia.net/content/horus.mp4',
            $registry->normalize('video_player.content_url', 'https://cdn.horusmedia.net/content/horus.mp4')
        );
        try {
            $registry->normalize('video_player.content_url', 'http://cdn.horusmedia.net/content/horus.mp4');
            $this->fail('Non-HTTPS platform video URL was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

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
        GlobalSetting::query()->updateOrCreate(['key' => 'supply_chain.contact_email'], ['value' => 'compliance@example.com', 'changed_by' => $this->admin->id]);
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

    public function test_production_contact_migration_overrides_legacy_environment_without_polluting_tests(): void
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
        $this->assertDatabaseCount('static_global_artifact_changes', 0);
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

    public function test_ad_ops_can_manage_only_the_platform_video_setting_from_the_settings_ui(): void
    {
        $this->assertTrue($this->adOps->hasPermission('settings.view'));
        $this->assertTrue($this->adOps->hasPermission('video_player.manage'));
        $this->assertFalse($this->adOps->hasPermission('settings.manage'));

        $videoRoute = route('admin.settings.update', ['key' => 'video_player.content_url']);
        $response = $this->actingAs($this->adOps)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Platform video content URL')
            ->assertSee('video_player.content_url')
            ->assertDontSee('video_player.inventory_type')
            ->assertSee('video_player.autoplay_audio')
            ->assertSee('video_player.mid_roll_interval_seconds')
            ->assertSee($videoRoute, false)
            ->assertSee('Save setting');

        $this->actingAs($this->adOps)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put($videoRoute, [
                'value' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
            ])
            ->assertRedirect();

        $this->assertSame(
            'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
            GlobalSetting::query()->findOrFail('video_player.content_url')->value,
        );

        $this->actingAs($this->adOps)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.settings.update', ['key' => 'reporting.retry_delay_minutes']), ['value' => 60])
            ->assertForbidden();
    }

    public function test_global_video_audio_preferences_are_explicit_and_validated(): void
    {
        $values = ['video_player.autoplay_audio' => ['muted', 'prefer_audible']];
        $registry = app(TypedSettingsRegistry::class);
        foreach ($values as $key => $choices) {
            foreach ($choices as $choice) {
                $this->assertSame($choice, $registry->normalize($key, $choice));
                $this->actingAs($this->adOps)
                    ->withSession(['two_factor_passed_at' => now()->timestamp])
                    ->put(route('admin.settings.update', ['key' => $key]), ['value' => $choice])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->assertSame($choice, GlobalSetting::query()->findOrFail($key)->value);
            }
            $this->actingAs($this->adOps)
                ->withSession(['two_factor_passed_at' => now()->timestamp])
                ->put(route('admin.settings.update', ['key' => $key]), ['value' => 'invented'])
                ->assertSessionHasErrors('value');
            $this->actingAs($this->publisher)
                ->put(route('admin.settings.update', ['key' => $key]), ['value' => $choices[0]])
                ->assertForbidden();
        }
        $this->assertSame('prefer_audible', GlobalSetting::query()->findOrFail('video_player.autoplay_audio')->value);
    }

    public function test_additional_midroll_interval_defaults_bounds_permissions_and_audit(): void
    {
        $key = 'video_player.mid_roll_interval_seconds';
        $registry = app(TypedSettingsRegistry::class);
        $this->assertSame(60, app(GlobalSettingsService::class)->get($key));
        foreach ([0, 30, 60, 600] as $value) {
            $this->assertSame($value, $registry->normalize($key, (string) $value));
            $this->actingAs($this->adOps)->withSession(['two_factor_passed_at' => now()->timestamp])
                ->put(route('admin.settings.update', ['key' => $key]), ['value' => $value])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($value, GlobalSetting::query()->findOrFail($key)->value);
            $this->assertSame($value, config('horus.video_mid_roll_interval_seconds'));
        }
        $this->assertSame(600, GlobalSetting::query()->findOrFail($key)->value);
        $this->assertDatabaseHas('audit_logs', ['event' => 'settings.global.updated', 'actor_id' => $this->adOps->id]);
        $this->assertSame('muted', config('horus.video_autoplay_audio'));
        $this->actingAs($this->publisher)->put(route('admin.settings.update', ['key' => $key]), ['value' => 0])->assertForbidden();
        $this->actingAs($this->adOps)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->delete(route('admin.settings.reset', ['key' => $key]))->assertRedirect();
        $this->assertSame(60, app(GlobalSettingsService::class)->get($key));
    }

    #[DataProvider('invalidAdditionalMidrollIntervals')]
    public function test_invalid_additional_midroll_interval_is_rejected_before_integer_coercion(mixed $value): void
    {
        $key = 'video_player.mid_roll_interval_seconds';
        app(GlobalSettingsService::class)->set($this->adOps, $key, 600);

        try {
            app(TypedSettingsRegistry::class)->normalize($key, $value);
            $this->fail('Invalid raw interval was accepted: '.json_encode($value));
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value', $exception->errors());
        }

        // Each named case gets a fresh actor and one HTTP write. A validation
        // matrix must not exhaust the real ten-per-minute sensitive limiter.
        $this->actingAs($this->adOps)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.settings.update', ['key' => $key]), ['value' => $value])
            ->assertStatus(302)->assertSessionHasErrors('value');

        $this->assertSame(600, GlobalSetting::query()->findOrFail($key)->value);
        $this->assertSame(600, config('horus.video_mid_roll_interval_seconds'));
        $this->assertSame(1, \App\Models\AuditLog::query()->where('event', 'settings.global.updated')->count());
    }

    public static function invalidAdditionalMidrollIntervals(): array
    {
        return [
            'negative integer' => [-1],
            'one is below enabled minimum' => [1],
            'twenty-nine is below enabled minimum' => [29],
            'above maximum' => [601],
            'fractional number' => [60.5],
            'fractional string' => ['60.5'],
            'non-numeric string' => ['invalid'],
            'empty string' => [''],
            'null value' => [null],
            'array value' => [[60]],
        ];
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

    public function test_video_permission_migration_repairs_all_registered_super_admin_permissions(): void
    {
        $superRole = \App\Models\Role::whereNull('organization_id')
            ->where('name', RoleName::SuperAdmin->value)
            ->firstOrFail();
        $settingsManage = \App\Models\Permission::where('name', 'settings.manage')->firstOrFail();

        $superRole->permissions()->detach($settingsManage->id);
        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $superRole->id,
            'permission_id' => $settingsManage->id,
        ]);

        $migration = require database_path('migrations/2026_09_28_210000_add_video_player_manage_permission.php');
        $migration->up();

        foreach (\App\Models\Permission::query()->pluck('id') as $permissionId) {
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $superRole->id,
                'permission_id' => $permissionId,
            ]);
        }
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
