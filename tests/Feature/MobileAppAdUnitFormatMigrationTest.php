<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, RoleName};
use App\Models\{MobileApp, MobileAppAdUnit};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\{InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class MobileAppAdUnitFormatMigrationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites;

    protected function setUp(): void
    {
        parent::setUp();
        // DDL commits implicitly on MySQL. Use an isolated, freshly migrated
        // test database rather than a transaction or unrelated legacy rollbacks.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->beforeApplicationDestroyed(function () {
            Schema::dropAllTables();
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$inMemoryConnections = [];
        });
    }

    public function test_existing_units_survive_upgrade_and_rollback_never_discards_shared_formats(): void
    {
        $migration = require database_path('migrations/2026_10_19_173000_allow_mobile_ad_unit_paths_across_formats.php');
        $migration->down();
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $app = MobileApp::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id,
            'public_id' => 'hma_format_migration', 'name' => 'Existing app', 'platform' => 'android',
            'store' => 'google_play', 'store_url' => 'https://play.google.com/store/apps/details?id=com.horus.migration',
            'store_app_id' => 'com.horus.migration', 'developer_domain' => 'developer.example.com', 'status' => 'APPROVED',
        ]);
        $attributes = ['organization_id' => $publisher->organization_id, 'mobile_app_id' => $app->id,
            'name' => 'Existing banner', 'format' => 'banner', 'ad_unit_path' => '/123/shared', 'is_enabled' => false];
        $original = MobileAppAdUnit::withoutGlobalScopes()->create($attributes);
        $before = $original->fresh()->getRawOriginal();
        $migration->up();
        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $index = collect(Schema::getIndexes('mobile_app_ad_units'))->firstWhere('name', 'mobile_app_ad_units_app_path_format_unique');
        $this->assertSame(['mobile_app_id', 'ad_unit_path', 'format'], $index['columns']);
        $this->assertTrue($index['unique']);

        $second = MobileAppAdUnit::withoutGlobalScopes()->create(array_replace($attributes, ['name' => 'Rewarded', 'format' => 'rewarded', 'is_enabled' => true]));
        try {
            DB::transaction(fn () => MobileAppAdUnit::withoutGlobalScopes()->create($attributes));
            $this->fail('The database must reject a duplicate app, path and format.');
        } catch (QueryException) {
            $this->assertSame(2, $app->adUnits()->count());
        }
        try {
            $migration->down();
            $this->fail('Rollback must preserve units that share a path.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back mobile ad unit format sharing', $exception->getMessage());
            $this->assertSame(2, $app->adUnits()->count());
        } finally {
            $second->delete();
        }
        $migration->down();
        $this->assertSame($before, $original->fresh()->getRawOriginal());
        $migration->up();
        $this->assertSame($before, $original->fresh()->getRawOriginal());
    }
}
