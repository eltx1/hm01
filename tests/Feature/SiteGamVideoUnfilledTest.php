<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, ReportSource, ReportSourceConnection, SiteGamReportBinding, SiteGamUnfilledReport, SiteGamVideoReportBinding, SiteGamVideoUnfilledReport};
use App\Services\Reporting\{GamAdUnitReportClient, ReportImportService, SiteGamUnfilledProjection, SiteGamUnfilledSynchronizer};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class SiteGamVideoUnfilledTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const DAY = '2034-04-15';
    private const CSV = "Dimension.DATE,Dimension.AD_UNIT_ID,Column.TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS\n";

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2034-04-18 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $gam = $this->makeGamConnection($admin->organization, $admin);

        return compact('admin', 'user', 'publisher', 'site', 'gam');
    }

    private function binding(array $context, bool $video = true, string $unit = '80412', ?string $id = null): SiteGamReportBinding
    {
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id,
            'report_source_id' => ReportSource::where('code', $video ? ReportSourceCode::GamVideoAdUnit->value : ReportSourceCode::GamAdUnit->value)->firstOrFail()->id,
            'name' => 'Independent Unfilled fixture', 'connection_type' => 'TEST', 'connection_id' => 'fixture-'.($video ? 'video' : 'display'),
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
        $model = $video ? SiteGamVideoReportBinding::class : SiteGamReportBinding::class;
        $binding = $model::withoutGlobalScopes()->create([
            ...($id ? ['id' => $id] : []),
            'organization_id' => $context['publisher']->organization_id, 'site_id' => $context['site']->id,
            'gam_connection_id' => $context['gam']->id, 'report_source_connection_id' => $connection->id,
            'network_code' => $context['gam']->network_code, 'ad_unit_id' => $unit,
            'ad_unit_name' => 'Fixture unit', 'ad_unit_code' => 'fixture-unit',
            'starts_on' => self::DAY, 'created_by' => $context['admin']->id,
        ]);
        $connection->update(['connection_type' => $binding->connectionType(), 'connection_id' => $binding->id]);

        return $binding;
    }

    private function fact(array $context, SiteGamReportBinding $binding, array $overrides = []): DailyReport
    {
        // Import before marking the fixture as a bound source: production imports
        // use the connector's fully verified site scope; this isolates projection.
        $connection = $binding->connection;
        $connection->update(['connection_type' => 'TEST']);
        $day = CarbonImmutable::parse(self::DAY);
        $savedDates = [];
        foreach (['site_gam_report_bindings', 'site_gam_video_report_bindings'] as $table) {
            $savedDates[$table] = DB::table($table)->where('site_id', $context['site']->id)->pluck('starts_on', 'id');
            DB::table($table)->where('site_id', $context['site']->id)->update(['starts_on' => $day->addDay()->toDateString()]);
        }
        $job = app(ReportImportService::class)->importRows($connection, [[
            'date' => self::DAY, 'site_id' => $context['site']->id, 'publisher_id' => $context['publisher']->id,
            'gam_connection_id' => $binding->gam_connection_id, 'gam_ad_unit_id' => $binding->ad_unit_id,
            'currency' => 'USD', 'ad_requests' => 120, 'matched_requests' => 100, 'impressions' => 70,
            'gross_revenue_minor' => 1234, 'unfilled_impressions' => null,
            'gam_report_basis' => 'AD_EXCHANGE_V1', 'gam_report_site' => $context['site']->primary_domain,
            'gam_report_scope' => 'fixture-exact-hostname', ...$overrides,
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $context['admin']);
        $connection->update(['connection_type' => $binding->connectionType()]);
        foreach ($savedDates as $table => $dates) {
            foreach ($dates as $id => $date) DB::table($table)->where('id', $id)->update(['starts_on' => $date]);
        }
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');

        return DailyReport::withoutGlobalScopes()->where('report_import_job_id', $job->id)->sole();
    }

    private function sidecar(SiteGamReportBinding $binding, int $value): SiteGamUnfilledReport
    {
        $video = $binding instanceof SiteGamVideoReportBinding;
        $model = $video ? SiteGamVideoUnfilledReport::class : SiteGamUnfilledReport::class;

        return $model::withoutGlobalScopes()->create([
            'organization_id' => $binding->organization_id, 'report_source_connection_id' => $binding->report_source_connection_id,
            ($video ? 'site_gam_video_report_binding_id' : 'site_gam_report_binding_id') => $binding->id,
            'gam_connection_id' => $binding->gam_connection_id, 'network_code' => $binding->network_code,
            'ad_unit_id' => $binding->ad_unit_id, 'report_date' => self::DAY, 'timezone' => 'UTC',
            'unfilled_impressions' => $value, 'google_report_job_id' => '98765', 'reported_at' => now(),
        ]);
    }

    private function projected(DailyReport ...$rows): ?int
    {
        return app(SiteGamUnfilledProjection::class)->total(collect($rows)->map(fn ($row) => $row->fresh(['dimension', 'connection.source'])));
    }

    public function test_video_and_primary_sidecars_remain_separate_even_with_the_same_binding_id(): void
    {
        $context = $this->context();
        $primary = $this->binding($context, false, '11111');
        $video = $this->binding($context, true, '80412', $primary->id);
        $displayFact = $this->fact($context, $primary);
        $videoFact = $this->fact($context, $video);
        $this->sidecar($primary, 17);
        $this->assertNull($this->projected($videoFact));
        $this->sidecar($video, 23);
        $this->assertSame(17, $this->projected($displayFact));
        $this->assertSame(23, $this->projected($videoFact));
        $this->assertSame(40, $this->projected($displayFact, $videoFact));
        $this->assertNull($videoFact->fresh()->unfilled_impressions);
    }

    public function test_same_network_unit_day_deduplicates_across_sources_but_disagreement_is_unknown(): void
    {
        $context = $this->context();
        $primary = $this->binding($context, false);
        $video = $this->binding($context);
        $first = $this->fact($context, $primary);
        $second = $this->fact($context, $video);
        $this->sidecar($primary, 31);
        $counter = $this->sidecar($video, 31);
        $this->assertSame(31, $this->projected($first, $second));
        $counter->update(['unfilled_impressions' => 32]);
        $this->assertNull($this->projected($first, $second));
    }

    public function test_video_provenance_and_unknown_values_fail_closed(): void
    {
        $context = $this->context();
        $video = $this->binding($context);
        $fact = $this->fact($context, $video, ['unfilled_impressions' => 99,
            'gam_report_basis' => null, 'gam_report_site' => null, 'gam_report_scope' => null]);
        $this->assertNull($this->projected($fact), 'Video never borrows a historical display counter.');
        $counter = $this->sidecar($video, 0);
        $this->assertSame(0, $this->projected($fact));
        foreach (['timezone' => 'Africa/Cairo', 'network_code' => 'wrong', 'ad_unit_id' => '99999'] as $field => $wrong) {
            $original = $counter->$field;
            $counter->update([$field => $wrong]);
            $this->assertNull($this->projected($fact), $field);
            $counter->update([$field => $original]);
        }
        $otherGam = $this->makeGamConnection($context['admin']->organization, $context['admin']);
        $counter->update(['gam_connection_id' => $otherGam->id]);
        $this->assertNull($this->projected($fact));
        $counter->update(['gam_connection_id' => $video->gam_connection_id]);
        $video->connection->update(['connection_type' => 'SITE_GAM_AD_UNIT']);
        $this->assertNull($this->projected($fact), 'Wrong source type cannot read a video sidecar.');
        $video->connection->update(['connection_type' => $video->connectionType()]);
        $video->update(['starts_on' => '2034-04-16']);
        $this->assertNull($this->projected($fact));
    }

    public function test_video_sync_uses_original_soap_counter_and_preserves_finance_on_failure(): void
    {
        $context = $this->context();
        $primary = $this->binding($context, false, '11111');
        $video = $this->binding($context, true, '80412', $primary->id);
        $fact = $this->fact($context, $video);
        $before = DB::table('daily_reports')->get()->toJson();
        $this->sidecar($primary, 17);
        $csv = self::CSV.self::DAY.",80412,23\n";
        $pending = true;
        $jobs = 0;
        $client = Mockery::mock(GamAdUnitReportClient::class);
        $client->shouldReceive('call')->andReturnUsing(function ($gam, $service, $method, $payload = []) use ($context, &$pending, &$jobs): array {
            if ($method === 'getCurrentNetwork') return ['networkCode' => $context['gam']->network_code, 'timeZone' => 'UTC'];
            if ($method === 'runReportJob') {
                $query = $payload['reportJob']['reportQuery'];
                $this->assertSame(['DATE', 'AD_UNIT_ID'], $query['dimensions']);
                $this->assertSame(['TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS'], $query['columns']);
                $this->assertSame('PUBLISHER', $query['timeZoneType']);
                $this->assertSame('80412', $query['statement']['values'][0]['value']['value']);
                $this->assertArrayNotHasKey('reportCurrency', $query);
                return ['id' => (string) ++$jobs];
            }
            if ($method === 'getReportJobStatus') return ['value' => $pending ? 'IN_PROGRESS' : 'COMPLETED'];
            $this->fail('Unexpected SOAP method '.$method);
        });
        $client->shouldReceive('download')->andReturnUsing(function () use (&$csv): string { return $csv; });
        $this->app->instance(GamAdUnitReportClient::class, $client);
        $sync = app(SiteGamUnfilledSynchronizer::class);
        $this->assertSame('PENDING', $sync->sync($video)['status']);
        $pending = false;
        $this->assertSame('COMPLETED', $sync->sync($video)['status']);
        $this->assertSame(1, $jobs, 'Pending job resumes without replacement.');
        $this->assertSame(23, SiteGamVideoUnfilledReport::withoutGlobalScopes()->sole()->unfilled_impressions);
        $this->assertSame(17, SiteGamUnfilledReport::withoutGlobalScopes()->sole()->unfilled_impressions);
        $this->assertSame(23, $this->projected($fact));
        $csv = self::CSV.self::DAY.",99999,999\n";
        $this->assertSame('SOURCE_UNAVAILABLE', $sync->sync($video)['status']);
        $this->assertSame(23, SiteGamVideoUnfilledReport::withoutGlobalScopes()->sole()->unfilled_impressions);
        $csv = self::CSV;
        $result = $sync->sync($video);
        $this->assertSame('COMPLETED', $result['status']);
        $this->assertSame(0, $result['stored_days']);
        $this->assertSame(23, SiteGamVideoUnfilledReport::withoutGlobalScopes()->sole()->unfilled_impressions, 'Absent dates never erase verified values.');
        $this->assertNull($video->connection->fresh()->last_error);
        $this->assertSame($before, DB::table('daily_reports')->get()->toJson());
        $this->assertDatabaseCount('hourly_reports', 0);
    }
    public function test_existing_primary_checkpoint_resumes_without_restarting_history(): void
    {
        $binding = $this->binding($this->context(), false);
        $identity = hash('sha256', implode('|', [SiteGamUnfilledSynchronizer::SCOPE, $binding->id,
            $binding->organization_id, $binding->site_id, $binding->gam_connection_id, $binding->network_code,
            $binding->ad_unit_id, $binding->connection->timezone, $binding->starts_on->toDateString(), null]));
        $binding->connection->update(['configuration' => ['unit_unfilled' => ['identity' => $identity,
            'pending' => ['id' => '4455', 'from' => self::DAY, 'to' => '2034-04-17', 'requested_at' => now()->toIso8601String()]]]]);
        $client = Mockery::mock(GamAdUnitReportClient::class);
        $client->shouldReceive('call')->once()->with(Mockery::type(\App\Models\GamConnection::class),
            'ReportService', 'getReportJobStatus', ['reportJobId' => '4455'])->andReturn(['value' => 'COMPLETED']);
        $client->shouldReceive('download')->once()->with(Mockery::type(\App\Models\GamConnection::class), '4455')
            ->andReturn(self::CSV.self::DAY.",80412,17\n");
        $this->app->instance(GamAdUnitReportClient::class, $client);
        $this->assertSame('COMPLETED', app(SiteGamUnfilledSynchronizer::class)->sync($binding)['status']);
        $this->assertSame(17, SiteGamUnfilledReport::withoutGlobalScopes()->sole()->unfilled_impressions);
        $this->assertDatabaseCount('site_gam_video_unfilled_reports', 0);
    }

}
