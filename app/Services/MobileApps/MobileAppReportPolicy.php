<?php

namespace App\Services\MobileApps;

use App\Models\{GamConnection, MobileAppReportBinding, ReportSourceConnection};
use App\Services\Reporting\SiteGamReportMetrics;
use Illuminate\Validation\ValidationException;

final class MobileAppReportPolicy
{
    public function accepts(ReportSourceConnection $connection, array $row, string $importType): ?bool
    {
        if ($connection->connection_type === 'MOBILE_APP_GAM') {
            $binding = MobileAppReportBinding::withoutGlobalScopes()->with('mobileApp')
                ->where('report_source_connection_id', $connection->id)->lockForUpdate()->find($connection->connection_id);
            if (! $binding || $importType !== 'API' || ! $connection->is_enabled
                || $row['date'] < $binding->starts_on->toDateString() || ($binding->ends_on && $row['date'] > $binding->ends_on->toDateString())
                || ($row['site_id'] ?? null) || ($row['mobile_app_id'] ?? null) !== $binding->mobile_app_id
                || ($row['publisher_id'] ?? null) !== $binding->mobileApp?->publisher_id
                || ($row['organization_id'] ?? null) !== $binding->organization_id || $connection->organization_id !== $binding->organization_id
                || ($row['gam_connection_id'] ?? null) !== $binding->gam_connection_id
                || (string) ($row['gam_ad_unit_id'] ?? '') !== $binding->ad_unit_id
                || ($row['gam_report_app'] ?? null) !== $binding->mobileApp->store_app_id
                || ($row['gam_report_basis'] ?? null) !== SiteGamReportMetrics::BASIS
                || ($row['gam_report_scope'] ?? null) !== app(MobileAppReportScope::class)->ensure($binding)['fingerprint']) {
                throw ValidationException::withMessages(['source' => 'Mobile app revenue must prove its exact Google network, unit, store ID, publisher and date.']);
            }
            return true;
        }
        if (! empty($row['mobile_app_id'])) throw ValidationException::withMessages(['source' => 'Use the dedicated Google mobile app report connection.']);
        $gamId = $row['gam_connection_id'] ?? ($connection->connection_type === 'GAM_CONNECTION' ? $connection->connection_id : null);
        if ($gamId) {
            $network = GamConnection::withoutGlobalScopes()->whereKey($gamId)->value('network_code');
            $bindings = MobileAppReportBinding::withoutGlobalScopes()->where('network_code', $network)
                ->whereDate('starts_on', '<=', $row['date'])->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $row['date']));
            $unit = $row['gam_ad_unit_id'] ?? $row['ad_unit_id'] ?? $row['dimension.ad_unit_id'] ?? null;
            if ($unit) $bindings->where('ad_unit_id', (string) $unit);
            if ($bindings->lockForUpdate()->exists()) return false;
        }
        return null;
    }
}
