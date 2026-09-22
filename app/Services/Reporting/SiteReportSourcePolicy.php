<?php

namespace App\Services\Reporting;

use App\Models\GamConnection;
use App\Models\MonetizationFinancialBinding;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteGamReportBinding;
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
        if ($connection->connection_type === 'SITE_GAM_AD_UNIT') {
            $binding = $bindings->with('site')->where('report_source_connection_id', $connection->id)->first();
            if ($importType !== 'API' || ! $binding || $binding->site_id !== ($row['site_id'] ?? null)
                || $binding->organization_id !== ($row['organization_id'] ?? null)
                || $binding->organization_id !== $connection->organization_id
                || $binding->site?->publisher_id !== ($row['publisher_id'] ?? null)
                || $binding->ad_unit_id !== (string) ($row['gam_ad_unit_id'] ?? '')
                || $binding->gam_connection_id !== ($row['gam_connection_id'] ?? null)) {
                throw ValidationException::withMessages(['source' => 'Ad-unit reports must come from the verified Google API binding for this website and date.']);
            }

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
            if ($network && $bindings->where('network_code', $network)->where('ad_unit_id', (string) $unit)->exists()) {
                return false;
            }
        }

        return true;
    }
}
