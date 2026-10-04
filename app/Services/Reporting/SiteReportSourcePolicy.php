<?php

namespace App\Services\Reporting;

use App\Models\GamConnection;
use App\Models\MonetizationFinancialBinding;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use App\Models\DailyReport;
use App\Models\HourlyReport;
use Illuminate\Validation\ValidationException;

final class SiteReportSourcePolicy
{
    /** A site-level source owns its effective dates, regardless of the previous import method. */
    public function accepts(ReportSourceConnection $connection, array $row, string $importType): bool
    {
        $gamId = $row['gam_connection_id'] ?? ($connection->connection_type === 'GAM_CONNECTION' ? $connection->connection_id : null);
        if ($gamId) {
            GamConnection::withoutGlobalScopes()->whereKey($gamId)->lockForUpdate()->first();
        }
        if (! empty($row['site_id'])) {
            Site::withoutGlobalScopes()->whereKey($row['site_id'])->lockForUpdate()->first();
        }
        $bindings = SiteGamReportBinding::withoutGlobalScopes()->whereDate('starts_on', '<=', $row['date'])
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $row['date']));
        if (SiteGamReportBinding::isSiteConnection($connection)) {
            $model = SiteGamReportBinding::modelForConnection($connection);
            $binding = $model::withoutGlobalScopes()->with('site')->where('report_source_connection_id', $connection->id)
                ->whereDate('starts_on', '<=', $row['date'])
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $row['date']))->find($connection->connection_id);
            if ($importType !== 'API' || ! $binding || $binding->site_id !== ($row['site_id'] ?? null)
                || $binding->organization_id !== ($row['organization_id'] ?? null)
                || $binding->organization_id !== $connection->organization_id
                || $binding->site?->publisher_id !== ($row['publisher_id'] ?? null)
                || $binding->ad_unit_id !== (string) ($row['gam_ad_unit_id'] ?? '')
                || $binding->gam_connection_id !== ($row['gam_connection_id'] ?? null)) {
                throw ValidationException::withMessages(['source' => 'Ad-unit reports must come from the verified Google API binding for this website and date.']);
            }
            $scope = app(SiteGamReportScope::class)->ensure($binding);
            app(SiteGamReportScope::class)->assertCurrent($binding, $scope);
            if (($row['gam_report_site'] ?? null) !== $scope['hostname']
                || ($row['gam_report_basis'] ?? null) !== SiteGamReportMetrics::BASIS
                || ($row['gam_report_scope'] ?? null) !== $scope['fingerprint'] || $row['date'] < $scope['effective_from']) {
                throw ValidationException::withMessages(['source' => 'The Google report must prove the exact website hostname and current effective scope.']);
            }
            app(SiteGamReportScope::class)->assertNoConflictingFacts($connection, $row['date'], $scope['fingerprint']);

            if ($binding instanceof SiteGamVideoReportBinding) $this->assertNoAmbiguousVideoFacts($binding, $row['date']);

            return true;
        }
        $providerFinancialConnection = in_array($connection->connection_type, ['DEMAND_ACCOUNT', 'BIDDER_ACCOUNT'], true);
        if ($providerFinancialConnection) {
            $financialBinding = MonetizationFinancialBinding::withoutGlobalScopes()
                ->where('report_source_connection_id', $connection->id)
                ->where('is_enabled', true)
                ->first();

            // An explicit Site GAM attestation makes Site GAM the canonical
            // financial source for this provider. Never admit the provider's
            // own financial rows into the ledger as well, or the same realized
            // revenue could be counted twice.
            if ((bool) data_get($financialBinding?->configuration, 'site_gam_included', false)) {
                return false;
            }
        } elseif (! empty($row['site_id'])
            && (clone $bindings)->where('site_id', $row['site_id'])->exists()) {
            return false;
        }
        // A full-network import may identify the Google unit before it has a local site mapping.
        $unit = $row['gam_ad_unit_id'] ?? $row['ad_unit_id'] ?? $row['dimension.ad_unit_id'] ?? null;
        if ($unit && $gamId) {
            $network = GamConnection::withoutGlobalScopes()->whereKey($gamId)->value('network_code');
            $videoOwnsUnit = SiteGamVideoReportBinding::withoutGlobalScopes()->whereDate('starts_on', '<=', $row['date'])
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $row['date']))
                ->where('network_code', $network)->where('ad_unit_id', (string) $unit)->exists();
            if ($network && ($bindings->where('network_code', $network)->where('ad_unit_id', (string) $unit)->exists() || $videoOwnsUnit)) {
                return false;
            }
        }

        // Video owns only its selected unit, never all revenue of this site.
        // Ambiguous CSV/legacy facts cannot safely coexist with payable video.
        if (! $providerFinancialConnection && ! empty($row['site_id'])) {
            $video = SiteGamVideoReportBinding::withoutGlobalScopes()->where('site_id', $row['site_id'])
                ->whereDate('starts_on', '<=', $row['date'])
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $row['date']))->first();
            if ($video && (! $unit || ! $gamId)) {
                throw ValidationException::withMessages(['source' => 'This website has payable Video reporting. The other source must prove a distinct network/ad unit before importing overlapping dates. Review its attribution first.']);
            }
        }

        return true;
    }

    private function assertNoAmbiguousVideoFacts(SiteGamVideoReportBinding $binding, string $date): void
    {
        foreach ([DailyReport::class, HourlyReport::class] as $model) {
            $facts = $model::withoutGlobalScopes()->whereDate('report_date', $date)
                ->where('report_source_connection_id', '!=', $binding->report_source_connection_id)
                ->whereHas('dimension', fn ($q) => $q->where('site_id', $binding->site_id))
                ->with('dimension', 'connection')->get();
            foreach ($facts as $fact) {
                if (in_array($fact->connection?->connection_type, ['DEMAND_ACCOUNT', 'BIDDER_ACCOUNT'], true)) continue;
                $external = $fact->dimension?->external_dimensions ?? [];
                $unit = $external['gam_ad_unit_id'] ?? $external['ad_unit_id'] ?? $external['dimension.ad_unit_id'] ?? null;
                $gam = $fact->dimension?->gam_connection_id
                    ?? ($fact->connection?->connection_type === 'GAM_CONNECTION' ? $fact->connection->connection_id : null);
                $network = $gam ? GamConnection::withoutGlobalScopes()->whereKey($gam)->value('network_code') : null;
                if (! $unit || ! $network || ((string) $network === $binding->network_code && (string) $unit === $binding->ad_unit_id)) {
                    throw ValidationException::withMessages(['source' => 'Existing source facts may already include Video revenue for this date. Review network/ad-unit attribution before importing; no historical money was changed.']);
                }
            }
        }
    }
}
