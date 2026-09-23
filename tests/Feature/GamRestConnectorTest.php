<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportSourceCode;
use App\Enums\RoleName;
use App\Services\Gam\GamConnectorManager;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\Connectors\GamReportConnector;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\ReportingBridge;
use Carbon\CarbonImmutable;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class GamRestConnectorTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, RefreshDatabase;

    public function test_rest_connector_reads_network_and_writes_ad_unit_without_dated_version(): void
    {
        $this->seedIdentity();
        $this->seed(ReportingSeeder::class);
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'REST', 'network_code' => '123456789', 'dry_run_default' => false,
        ]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789' => Http::response(['name' => 'networks/123456789', 'networkCode' => '123456789']),
            'https://admanager.googleapis.com/v1/networks/123456789/adUnits' => Http::response(['name' => 'networks/123456789/adUnits/42'], 200),
        ]);

        $connector = app(GamConnectorManager::class)->for($connection);
        $network = $connector->getCurrentNetwork();
        $adUnit = $connector->createAdUnit(['displayName' => 'Article top', 'adUnitCode' => 'article_top'], ['dry_run' => false]);

        $this->assertTrue($network->success);
        $this->assertTrue($adUnit->success);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://admanager.googleapis.com/v1/networks/123456789/adUnits'
            && ! str_contains($request->url(), 'v202'));
    }

    public function test_full_network_gam_reporting_requests_usd_even_when_network_metadata_is_aed(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'REST',
            'network_code' => '123456789',
            'dry_run_default' => false,
            'configuration' => ['currency' => 'AED'],
        ]);

        $google = new class implements GamSoapTransportInterface
        {
            public array $calls = [];

            public function call(\App\Models\GamConnection $connection, string $service, string $method, array $payload = []): array
            {
                $this->calls[] = compact('service', 'method', 'payload');

                $versions = app(\App\Services\Gam\GamSoapVersionResolver::class);
                $namespace = $versions->namespaceFor($versions->resolve());
                $reflection = new \ReflectionClass($namespace.'\\'.$service);
                app(\App\Services\Gam\GamSoapPayloadHydrator::class)
                    ->arguments($reflection->newInstanceWithoutConstructor(), $method, $payload, $namespace);

                return match ($method) {
                    'getCurrentNetwork' => [
                        'networkCode' => $connection->network_code,
                        'currencyCode' => 'AED',
                        'timeZone' => 'Asia/Dubai',
                    ],
                    'runReportJob' => ['id' => '77'],
                    'getReportJobStatus' => ['value' => 'COMPLETED'],
                    'getReportDownloadUrlWithOptions' => ['value' => 'https://storage.googleapis.com/report.csv?signature=private'],
                    default => throw new \RuntimeException('Unexpected Google call: '.$method),
                };
            }
        };
        $this->app->instance(GamSoapTransportInterface::class, $google);

        $headers = [
            'Dimension.DATE',
            'Dimension.AD_UNIT_ID',
            ...array_map(fn ($column) => 'Column.'.$column, array_keys(GamReportConnector::COLUMNS)),
        ];
        $values = [
            '2026-09-20', '1001',
            120, 100, 20, 95, 3, 123450000,
        ];
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers, escape: '');
        fputcsv($stream, $values, escape: '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        Http::fake(['https://storage.googleapis.com/*' => Http::response($csv)]);

        $reportConnection = app(ReportingBridge::class)->connectionForGam($connection, $actor);
        $this->assertSame('USD', $reportConnection->currency);
        $this->assertSame('AED', data_get($reportConnection->configuration, 'source_network_currency'));
        $this->assertSame('USD', data_get($reportConnection->configuration, 'report_currency'));

        $day = CarbonImmutable::parse('2026-09-20');
        $result = app(GamReportConnector::class)->fetch(
            $reportConnection,
            $day,
            $day,
            ReportGranularity::Daily,
            ReportFinality::Finalized,
        );

        $this->assertSame('USD', data_get($result, 'metadata.report_currency'));
        $this->assertSame('AED', data_get($result, 'metadata.source_network_currency'));
        $this->assertSame('Asia/Dubai', $reportConnection->fresh()->timezone);
        $this->assertSame(12345, data_get($result, 'rows.0.gross_revenue_minor'));
        $this->assertSame('USD', data_get($result, 'rows.0.currency'));

        $reportCall = collect($google->calls)->firstWhere('method', 'runReportJob');
        $this->assertSame('USD', data_get($reportCall, 'payload.reportJob.reportQuery.reportCurrency'));
        $this->assertSame('CUSTOM_DATE', data_get($reportCall, 'payload.reportJob.reportQuery.dateRangeType'));
        $this->assertSame(['DATE', 'AD_UNIT_ID'], data_get($reportCall, 'payload.reportJob.reportQuery.dimensions'));
        $this->assertSame('TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE', data_get($reportCall, 'payload.reportJob.reportQuery.columns.5'));

        // The scheduler may run every hour, but this metric set is not a valid
        // HOUR report in GAM. The financial import pipeline must refresh a
        // DAILY estimated snapshot instead of inventing hourly attribution.
        $job = app(ReportImportService::class)->runConnection(
            $reportConnection->fresh(),
            $day,
            $day,
            ReportGranularity::Hourly,
            ReportFinality::Estimated,
            $actor,
        );
        $this->assertSame(ReportGranularity::Daily, $job->granularity);
        $this->assertSame('COMPLETED', $job->status->value);
        $intradayCall = collect($google->calls)->where('method', 'runReportJob')->last();
        $this->assertSame(['DATE', 'AD_UNIT_ID'], data_get($intradayCall, 'payload.reportJob.reportQuery.dimensions'));
    }

    public function test_existing_non_usd_full_network_history_is_preserved_while_future_reporting_cuts_over_to_usd(): void
    {
        $this->seedIdentity();
        $this->seed(ReportingSeeder::class);
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $gam = $this->makeGamConnection($organization, $actor, [
            'driver' => 'REST',
            'network_code' => '223456789',
            'dry_run_default' => false,
            'configuration' => ['currency' => 'AED'],
        ]);
        $source = ReportSource::query()->where('code', ReportSourceCode::HorusGam->value)->firstOrFail();
        $legacy = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'report_source_id' => $source->id,
            'name' => 'Legacy AED GAM',
            'connection_type' => 'GAM_CONNECTION',
            'connection_id' => $gam->id,
            'account_identifier' => $gam->network_code,
            'currency' => 'AED',
            'timezone' => 'UTC',
            'status' => 'ACTIVE',
            'is_enabled' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $day = CarbonImmutable::parse('2026-09-20');
        $job = app(ReportImportService::class)->importRows(
            $legacy,
            [['date' => $day->toDateString(), 'gross_revenue_minor' => 10000, 'currency' => 'AED']],
            ReportGranularity::Daily,
            ReportFinality::Finalized,
            $day,
            $day,
            $actor,
            'legacy-aed-financial-history',
            importType: 'API',
        );
        $this->assertSame('COMPLETED', $job->status->value);

        $canonical = app(ReportingBridge::class)->connectionForGam($gam, $actor);

        $this->assertNotSame($legacy->id, $canonical->id);
        $this->assertSame('USD', $canonical->currency);
        $this->assertTrue($canonical->is_enabled);
        $this->assertSame('ACTIVE', $canonical->status->value);
        $this->assertSame($legacy->id, data_get($canonical->configuration, 'legacy_connection_id'));
        $this->assertTrue((bool) data_get($canonical->configuration, 'canonical_currency_rebackfill_required'));

        $legacy->refresh();
        $this->assertSame('AED', $legacy->currency);
        $this->assertSame('GAM_CONNECTION_LEGACY', $legacy->connection_type);
        $this->assertFalse($legacy->is_enabled);
        $this->assertSame('DISABLED', $legacy->status->value);
        $this->assertSame('LEGACY_SOURCE_CURRENCY', data_get($legacy->configuration, 'currency_policy'));
        $this->assertSame('USD', data_get($legacy->configuration, 'canonical_currency_cutover_to'));

        $this->assertDatabaseHas('daily_reports', [
            'report_source_connection_id' => $legacy->id,
            'currency' => 'AED',
            'gross_revenue_minor' => 10000,
        ]);
        $this->assertDatabaseMissing('daily_reports', [
            'report_source_connection_id' => $canonical->id,
            'currency' => 'AED',
        ]);

        $sameCanonical = app(ReportingBridge::class)->connectionForGam($gam, $actor);
        $this->assertSame($canonical->id, $sameCanonical->id);
        $this->assertSame(2, ReportSourceConnection::withoutGlobalScopes()
            ->where('report_source_id', $source->id)
            ->where('connection_id', $gam->id)
            ->count());
    }

    public function test_rest_dry_run_is_audited_without_external_request(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, ['driver' => 'REST', 'dry_run_default' => true]);
        Http::fake();

        $result = app(GamConnectorManager::class)->for($connection)->createPlacement(['displayName' => 'Homepage']);

        $this->assertTrue($result->dryRun);
        Http::assertNothingSent();
        $this->assertDatabaseHas('gam_api_operations', ['operation' => 'createPlacement', 'service' => 'REST:placements', 'status' => 'DRY_RUN']);
    }

    public function test_rest_order_batch_uses_the_google_aip_requests_envelope(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'REST', 'network_code' => '123456789', 'dry_run_default' => false,
        ]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789/orders:batchCreate' => Http::response([
                'orders' => [['name' => 'networks/123456789/orders/77']],
            ]),
        ]);

        $result = app(GamConnectorManager::class)->for($connection)->createOrder([
            'displayName' => 'Horus campaign',
            'advertiser' => 'networks/123456789/companies/1',
            'trafficker' => 'networks/123456789/users/2',
        ], ['dry_run' => false]);

        $this->assertTrue($result->success);
        Http::assertSent(fn ($request) => $request->url() === 'https://admanager.googleapis.com/v1/networks/123456789/orders:batchCreate'
            && data_get($request->data(), 'requests.0.parent') === 'networks/123456789'
            && data_get($request->data(), 'requests.0.order.displayName') === 'Horus campaign');
    }

    public function test_rest_custom_targeting_translates_soap_neutral_names_to_v1_resources(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, [
            'driver' => 'HYBRID', 'network_code' => '123456789', 'dry_run_default' => false,
        ]);
        $this->cacheToken($connection);
        Http::fake([
            'https://admanager.googleapis.com/v1/networks/123456789/customTargetingKeys' => Http::response(['name' => 'networks/123456789/customTargetingKeys/11']),
            'https://admanager.googleapis.com/v1/networks/123456789/customTargetingValues' => Http::response(['name' => 'networks/123456789/customTargetingValues/22']),
        ]);
        $connector = app(GamConnectorManager::class)->for($connection);

        $key = $connector->createCustomTargetingKey(['name' => 'hb_pb', 'displayName' => 'Price', 'type' => 'PREDEFINED', 'reportableType' => 'ON'], ['dry_run' => false]);
        $value = $connector->createCustomTargetingValue(['customTargetingKeyId' => $key->data['id'], 'name' => '1.00', 'displayName' => '1.00', 'matchType' => 'EXACT'], ['dry_run' => false]);

        $this->assertSame('11', $key->data['id']);
        $this->assertSame('22', $value->data['id']);
        Http::assertSent(fn ($request) => data_get($request->data(), 'adTagName') === 'hb_pb' && ! array_key_exists('name', $request->data()));
        Http::assertSent(fn ($request) => data_get($request->data(), 'customTargetingKey') === 'networks/123456789/customTargetingKeys/11'
            && data_get($request->data(), 'adTagName') === '1.00');
    }

    public function test_unpublished_rest_write_is_planned_through_audited_soap_fallback(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $actor = $this->makeUser($organization, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($organization, $actor, ['driver' => 'REST']);
        Http::fake();

        $result = app(GamConnectorManager::class)->for($connection)->createCreative([
            '__type' => 'ThirdPartyCreative', 'name' => 'Creative',
        ], ['dry_run' => true]);

        $this->assertTrue($result->success);
        $this->assertTrue($result->dryRun);
        $this->assertDatabaseHas('gam_api_operations', [
            'operation' => 'createCreative',
            'service' => 'SOAP:CreativeService',
            'method' => 'createCreatives',
            'status' => 'DRY_RUN',
        ]);
        Http::assertNothingSent();
    }

    private function cacheToken($connection): void
    {
        $credential = $connection->credential;
        $key = 'gam:oauth:'.hash('sha256', $connection->id.'|'.($credential->rotated_at?->timestamp ?? '0'));
        Cache::put($key, Crypt::encryptString('test-access-token'), now()->addMinutes(30));
    }
}
