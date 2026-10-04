<?php

namespace App\Services\Reporting;

use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\ReportImportStatus;
use App\Enums\ReportSourceCode;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\HourlyReport;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteGamVideoReportBinding;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SiteGamVideoReportingService
{
    public function __construct(private readonly GamAdUnitReportClient $google, private readonly AuditRecorder $audit,
        private readonly SiteGamReportScope $scopes) {}

    public function availableConnections(Site $site): Builder
    {
        return GamConnection::withoutGlobalScopes()->whereNull('deleted_at')->where('is_enabled', true)
            ->where(fn (Builder $query) => $query->where('organization_id', $site->organization_id)
                ->orWhereHas('organization', fn (Builder $org) => $org->where('type', OrganizationType::HorusMedia->value))
                ->orWhere(fn (Builder $partner) => $partner->where('type', GamConnectionType::McmPartnerGam->value)
                    ->whereHas('organization', fn (Builder $org) => $org->where('type', OrganizationType::Partner->value))));
    }

    public function bind(Site $site, string $gamId, string $unitInput, User $actor): SiteGamVideoReportBinding
    {
        abort_unless($actor->isHorusAdministrator() && $actor->hasPermission('reporting.sources.manage'), 403);
        $gam = $this->availableConnections($site)->find($gamId);
        if (! $gam) {
            throw ValidationException::withMessages(['gam_connection_id' => 'Choose an active GAM connection available to this publisher.']);
        }
        $units = $this->google->units($gam, trim($unitInput));
        if (count($units) !== 1) {
            throw ValidationException::withMessages(['ad_unit' => count($units) === 0
                ? 'No ad unit matches this name, code or ID in the selected network.'
                : 'More than one ad unit matches. Select its numeric ID: '.implode(', ', array_column($units, 'id')).'.']);
        }
        $unit = $units[0];
        $network = $this->google->call($gam, 'NetworkService', 'getCurrentNetwork');
        if ((string) ($network['networkCode'] ?? '') !== (string) $gam->network_code
            || ! preg_match('/^\d+$/D', (string) ($unit['id'] ?? ''))
            || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))
            || ! in_array($network['timeZone'] ?? '', timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw ValidationException::withMessages(['gam_connection_id' => 'Google returned incomplete or inconsistent network information.']);
        }

        $reportCurrency = $this->canonicalCurrency();
        $networkCurrency = strtoupper((string) $network['currencyCode']);

        return DB::transaction(function () use ($site, $gam, $unit, $network, $networkCurrency, $reportCurrency, $actor): SiteGamVideoReportBinding {
            GamConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($gam->id);
            Site::withoutGlobalScopes()->lockForUpdate()->findOrFail($site->id);
            $current = SiteGamVideoReportBinding::withoutGlobalScopes()->where('active_site_id', $site->id)->first();
            if ($current && $current->gam_connection_id === $gam->id && $current->ad_unit_id === (string) $unit['id']
                && $current->connection->timezone === $network['timeZone']) {
                app(SiteGamUnitClaims::class)->reserve($gam->network_code.':'.$unit['id'], $current->connectionType(), $current->id);
                $current->update(['ad_unit_name' => $unit['name'], 'ad_unit_code' => $unit['adUnitCode']]);
                $this->normalizeConnectionCurrency($current, $networkCurrency, $reportCurrency, $actor);
                if (! $current->connection->is_enabled || $current->connection->status->value === 'DISABLED') {
                    $current->connection->update(['is_enabled' => true, 'status' => 'ACTIVE', 'updated_by' => $actor->id]);
                    $this->audit->record('reporting.site_gam_video.reenabled', $site->organization_id, $actor, $current);
                }
                $this->scopes->ensure($current);

                return $current->fresh(['connection']);
            }
            $key = $gam->network_code.':'.$unit['id'];
            if (SiteGamVideoReportBinding::withoutGlobalScopes()->where('active_unit_key', $key)->where('site_id', '!=', $site->id)->exists()) {
                throw ValidationException::withMessages(['ad_unit' => 'This ad unit already supplies reports for another website.']);
            }
            $today = CarbonImmutable::now($network['timeZone'])->startOfDay();
            $starts = $today; // Forward-only opt-in: never add historical Video credits implicitly.
            // Serialize the cutover with financial closing as well as report imports.
            $period = app(FinancialPeriodService::class)->periodFor($today, $reportCurrency);
            FinancialPeriod::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
            $networkConnections = ReportSourceConnection::withoutGlobalScopes()->where('connection_type', 'GAM_CONNECTION')
                ->whereIn('connection_id', GamConnection::withoutGlobalScopes()->where('network_code', $gam->network_code)->select('id'))->pluck('id');
            // Preserve all previously imported days; a source change never silently rewrites history.
            foreach ([DailyReport::class, HourlyReport::class] as $model) {
                $last = $model::withoutGlobalScopes()->where(fn (Builder $q) => $q
                    ->whereHas('dimension', fn (Builder $dimension) => $dimension->where('site_id', $site->id))
                    ->orWhereIn('report_source_connection_id', $networkConnections))->max('report_date');
                if ($last) {
                    $starts = $starts->max(CarbonImmutable::parse($last, $network['timeZone'])->addDay());
                }
            }
            $lockedEnd = FinancialPeriod::query()->where('currency', $reportCurrency)->where('status', '!=', 'OPEN')->max('ends_on');
            if ($lockedEnd) {
                $starts = $starts->max(CarbonImmutable::parse($lockedEnd, $network['timeZone'])->addDay());
            }
            $previousEnd = app(SiteGamUnitClaims::class)->lastOwnedDay((string) $gam->network_code, (string) $unit['id']);
            if ($previousEnd) {
                $starts = $starts->max(CarbonImmutable::parse($previousEnd, $network['timeZone'])->addDay());
            }
            if ($current) {
                app(SiteGamUnitClaims::class)->release($current);
                $starts = $starts->max($today)->max($current->starts_on->addDay());
                $current->update(['active_site_id' => null, 'active_unit_key' => null, 'ends_on' => $starts->subDay()->toDateString()]);
                app(SiteGamVideoJobWindow::class)->retire($current);
                // Historical bindings remain importable until their final day has been reconciled.
            }
            $source = ReportSource::query()->firstOrCreate(['code' => ReportSourceCode::GamVideoAdUnit->value], [
                'name' => config('reporting.sources.GAM_VIDEO_AD_UNIT.name'), 'is_primary' => false, 'is_enabled' => true,
                'capabilities' => ['API', 'DAILY', 'FINALIZED_API'],
            ]);
            $id = (string) Str::ulid();
            app(SiteGamUnitClaims::class)->reserve($key, 'SITE_GAM_VIDEO_AD_UNIT', $id);
            $connection = ReportSourceConnection::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id, 'report_source_id' => $source->id,
                'name' => Str::limit($site->display_name, 220, '').' — Video GAM ad unit', 'connection_type' => 'SITE_GAM_VIDEO_AD_UNIT',
                'connection_id' => $id, 'account_identifier' => $key,
                'currency' => $reportCurrency, 'timezone' => $network['timeZone'],
                'configuration' => [
                    'currency_policy' => 'CANONICAL_REPORTING_CURRENCY',
                    'report_currency' => $reportCurrency,
                    'source_network_currency' => $networkCurrency,
                ],
                'status' => 'ACTIVE', 'is_enabled' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $binding = SiteGamVideoReportBinding::withoutGlobalScopes()->create([
                'id' => $id, 'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
                'active_site_id' => $site->id, 'active_unit_key' => $key,
                'network_code' => (string) $gam->network_code, 'ad_unit_id' => (string) $unit['id'],
                'ad_unit_name' => $unit['name'], 'ad_unit_code' => $unit['adUnitCode'],
                'starts_on' => $starts->toDateString(), 'created_by' => $actor->id,
            ]);
            $this->scopes->ensure($binding);
            $this->audit->record('reporting.site_gam_video.connected', $site->organization_id, $actor, $binding,
                newValues: $binding->only(['site_id', 'gam_connection_id', 'network_code', 'ad_unit_id', 'starts_on']) + [
                    'report_currency' => $reportCurrency,
                    'source_network_currency' => $networkCurrency,
                ]);

            return $binding->fresh(['connection']);
        });
    }


    public function disable(Site $site, User $actor): void
    {
        abort_unless($actor->isHorusAdministrator() && $actor->hasPermission('reporting.sources.manage'), 403);
        DB::transaction(function () use ($site, $actor): void {
            Site::withoutGlobalScopes()->lockForUpdate()->findOrFail($site->id);
            $binding = SiteGamVideoReportBinding::withoutGlobalScopes()->where('active_site_id', $site->id)->lockForUpdate()->first();
            if (! $binding) return;
            $today = CarbonImmutable::now($binding->connection->timezone)->startOfDay();
            $cancelled = $binding->starts_on->toDateString() > $today->toDateString();
            $end = $cancelled ? $binding->starts_on->subDay() : $today;
            $binding->update(['active_site_id' => null, 'active_unit_key' => null, 'ends_on' => $end->toDateString(),
                'cancelled_at' => $cancelled ? now() : null]);
            if ($cancelled) $binding->connection->update(['is_enabled' => false, 'status' => 'DISABLED', 'updated_by' => $actor->id]);
            app(SiteGamUnitClaims::class)->release($binding);
            app(SiteGamVideoJobWindow::class)->retire($binding);
            // End only future ownership. Retain source refresh so the final owned
            // day can finalize and reach the normal statement/settlement gate.
            $this->audit->record('reporting.site_gam_video.disconnected', $site->organization_id, $actor, $binding,
                newValues: ['ends_on' => $end->toDateString(), 'historical_money_changed' => false]);
        });
    }

    private function canonicalCurrency(): string
    {
        $currency = strtoupper(trim((string) config('reporting.canonical_currency', 'USD')));

        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'USD';
    }

    private function normalizeConnectionCurrency(
        SiteGamVideoReportBinding $binding,
        string $networkCurrency,
        string $reportCurrency,
        User $actor,
    ): void {
        $connection = $binding->connection;
        $configuration = (array) ($connection->configuration ?? []);
        $changed = strtoupper((string) $connection->currency) !== $reportCurrency
            || data_get($configuration, 'report_currency') !== $reportCurrency
            || data_get($configuration, 'source_network_currency') !== $networkCurrency;

        if (! $changed) {
            return;
        }

        $lockedPeriodIds = FinancialPeriod::query()
            ->where('status', '!=', 'OPEN')
            ->pluck('id');
        $hasLockedRows = $lockedPeriodIds->isNotEmpty()
            && (DailyReport::withoutGlobalScopes()
                ->where('report_source_connection_id', $connection->id)
                ->whereIn('financial_period_id', $lockedPeriodIds)
                ->exists()
                || HourlyReport::withoutGlobalScopes()
                    ->where('report_source_connection_id', $connection->id)
                    ->whereIn('financial_period_id', $lockedPeriodIds)
                    ->exists());

        if ($hasLockedRows && strtoupper((string) $connection->currency) !== $reportCurrency) {
            throw ValidationException::withMessages([
                'gam_connection_id' => 'This reporting source has closed historical accounting in '.$connection->currency.'. Horus will not relabel closed money. Create a Finance-approved USD cutover from the next open day.',
            ]);
        }

        $oldCurrency = strtoupper((string) $connection->currency);
        unset($configuration['google_jobs'], $configuration['sync_due']);
        $configuration['currency_policy'] = 'CANONICAL_REPORTING_CURRENCY';
        $configuration['report_currency'] = $reportCurrency;
        $configuration['source_network_currency'] = $networkCurrency;
        $configuration['currency_normalized_at'] = now()->toIso8601String();

        $connection->update([
            'currency' => $reportCurrency,
            'configuration' => $configuration,
            'last_error' => null,
            'updated_by' => $actor->id,
        ]);

        if ($oldCurrency !== $reportCurrency) {
            ReportImportJob::withoutGlobalScopes()
                ->where('report_source_connection_id', $connection->id)
                ->whereIn('status', [
                    ReportImportStatus::Pending->value,
                    ReportImportStatus::Failed->value,
                ])
                ->update([
                    'status' => ReportImportStatus::Duplicate->value,
                    'error_message' => null,
                    'next_retry_at' => null,
                    'completed_at' => now(),
                ]);

            $this->audit->record(
                'reporting.site_gam_video.currency_normalized',
                $binding->organization_id,
                $actor,
                $binding,
                ['report_currency' => $oldCurrency, 'source_network_currency' => $networkCurrency],
                ['report_currency' => $reportCurrency, 'source_network_currency' => $networkCurrency],
                ['reimport_required' => true],
            );
        }
    }
}
