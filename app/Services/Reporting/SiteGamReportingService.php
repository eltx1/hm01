<?php

namespace App\Services\Reporting;

use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\ReportSourceCode;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\GamConnection;
use App\Models\HourlyReport;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SiteGamReportingService
{
    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly AuditRecorder $audit,
        private readonly SiteGamCurrencyPolicy $currencyPolicy,
    ) {}

    public function availableConnections(Site $site): Builder
    {
        return GamConnection::withoutGlobalScopes()->whereNull('deleted_at')->where('is_enabled', true)
            ->where(fn (Builder $query) => $query->where('organization_id', $site->organization_id)
                ->orWhereHas('organization', fn (Builder $org) => $org->where('type', OrganizationType::HorusMedia->value))
                ->orWhere(fn (Builder $partner) => $partner->where('type', GamConnectionType::McmPartnerGam->value)
                    ->whereHas('organization', fn (Builder $org) => $org->where('type', OrganizationType::Partner->value))));
    }

    public function bind(Site $site, string $gamId, string $unitInput, User $actor): SiteGamReportBinding
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
        $reportingCurrency = $this->currencyPolicy->currency();
        if ((string) ($network['networkCode'] ?? '') !== (string) $gam->network_code
            || ! preg_match('/^\d+$/D', (string) ($unit['id'] ?? ''))
            || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))
            || ! in_array($network['timeZone'] ?? '', timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)) {
            throw ValidationException::withMessages(['gam_connection_id' => 'Google returned incomplete or inconsistent network information.']);
        }

        return DB::transaction(function () use ($site, $gam, $unit, $network, $reportingCurrency, $actor): SiteGamReportBinding {
            GamConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($gam->id);
            Site::withoutGlobalScopes()->lockForUpdate()->findOrFail($site->id);
            $current = SiteGamReportBinding::withoutGlobalScopes()->where('active_site_id', $site->id)->first();
            if ($current && $current->gam_connection_id === $gam->id && $current->ad_unit_id === (string) $unit['id']
                && $current->connection->timezone === $network['timeZone']) {
                $current->update(['ad_unit_name' => $unit['name'], 'ad_unit_code' => $unit['adUnitCode']]);
                $configuration = (array) ($current->connection->configuration ?? []);
                $configuration['network_currency'] = strtoupper((string) $network['currencyCode']);
                $configuration['canonical_report_currency'] = $reportingCurrency;
                $current->connection->update([
                    'configuration' => $configuration,
                    'updated_by' => $actor->id,
                ]);
                if (! $current->connection->is_enabled || $current->connection->status->value === 'DISABLED') {
                    $current->connection->update(['is_enabled' => true, 'status' => 'ACTIVE', 'updated_by' => $actor->id]);
                    $this->audit->record('reporting.site_gam.reenabled', $site->organization_id, $actor, $current);
                }

                return $this->currencyPolicy->enforce($current->fresh(['connection', 'site', 'gamConnection']), $actor);
            }
            $key = $gam->network_code.':'.$unit['id'];
            if (SiteGamReportBinding::withoutGlobalScopes()->where('active_unit_key', $key)->where('site_id', '!=', $site->id)->exists()) {
                throw ValidationException::withMessages(['ad_unit' => 'This ad unit already supplies reports for another website.']);
            }
            $today = CarbonImmutable::now($network['timeZone'])->startOfDay();
            $starts = $today->startOfMonth();
            // Serialize the cutover with financial closing as well as report imports.
            $period = app(FinancialPeriodService::class)->periodFor($today, $reportingCurrency);
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
            $lockedEnd = FinancialPeriod::query()->where('currency', $reportingCurrency)->where('status', '!=', 'OPEN')->max('ends_on');
            if ($lockedEnd) {
                $starts = $starts->max(CarbonImmutable::parse($lockedEnd, $network['timeZone'])->addDay());
            }
            $previousEnd = SiteGamReportBinding::withoutGlobalScopes()->where('network_code', $gam->network_code)
                ->where('ad_unit_id', (string) $unit['id'])->whereNotNull('ends_on')->max('ends_on');
            if ($previousEnd) {
                $starts = $starts->max(CarbonImmutable::parse($previousEnd, $network['timeZone'])->addDay());
            }
            if ($current) {
                $starts = $starts->max($today)->max($current->starts_on->addDay());
                $current->update(['active_site_id' => null, 'active_unit_key' => null, 'ends_on' => $starts->subDay()->toDateString()]);
                // Historical bindings remain importable until their final day has been reconciled.
            }
            $source = ReportSource::query()->firstOrCreate(['code' => ReportSourceCode::GamAdUnit->value], [
                'name' => config('reporting.sources.GAM_AD_UNIT.name'), 'is_primary' => false, 'is_enabled' => true,
                'capabilities' => ['API', 'DAILY', 'FINALIZED_API'],
            ]);
            $id = (string) Str::ulid();
            $connection = ReportSourceConnection::withoutGlobalScopes()->create([
                'organization_id' => $site->organization_id, 'report_source_id' => $source->id,
                'name' => Str::limit($site->display_name, 220, '').' — GAM ad unit', 'connection_type' => 'SITE_GAM_AD_UNIT',
                'connection_id' => $id, 'account_identifier' => $key,
                'currency' => $reportingCurrency, 'timezone' => $network['timeZone'],
                'status' => 'ACTIVE', 'is_enabled' => true,
                'configuration' => [
                    'network_currency' => strtoupper((string) $network['currencyCode']),
                    'canonical_report_currency' => $reportingCurrency,
                ],
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $binding = SiteGamReportBinding::withoutGlobalScopes()->create([
                'id' => $id, 'organization_id' => $site->organization_id, 'site_id' => $site->id,
                'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
                'active_site_id' => $site->id, 'active_unit_key' => $key,
                'network_code' => (string) $gam->network_code, 'ad_unit_id' => (string) $unit['id'],
                'ad_unit_name' => $unit['name'], 'ad_unit_code' => $unit['adUnitCode'],
                'starts_on' => $starts->toDateString(), 'created_by' => $actor->id,
            ]);
            $this->audit->record('reporting.site_gam.connected', $site->organization_id, $actor, $binding,
                newValues: $binding->only(['site_id', 'gam_connection_id', 'network_code', 'ad_unit_id', 'starts_on']));

            return $binding;
        });
    }
}
