<?php

namespace App\Services\Reporting;

use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use App\Models\Site;
use App\Models\MobileAppReportBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Call inside binding transaction. Network mutex serializes physical-unit and exact-hostname claims. */
final class SiteGamUnitClaims
{
    /** Lock after a current GAM row read and before site/history reads. Never remove mutex rows. */
    public function lockNetwork(string $network): void
    {
        DB::table('site_gam_reporting_network_locks')->insertOrIgnore(['network_code' => $network]);
        DB::table('site_gam_reporting_network_locks')->where('network_code', $network)->lockForUpdate()->firstOrFail();
    }

    public function reserve(string $key, string $type, string $id, ?Site $site = null, ?string $from = null): string
    {
        [$network, $unit] = explode(':', $key, 2);
        $ownedKey = null;
        foreach ([SiteGamReportBinding::class, SiteGamVideoReportBinding::class, MobileAppReportBinding::class] as $model) {
            foreach ($model::withoutGlobalScopes()->where('network_code', $network)->where('ad_unit_id', $unit)
                ->whereNotNull('active_unit_key')->with('connection')->get() as $binding) {
                if ($binding->id === $id && $binding->connectionType() === $type) {
                    $ownedKey = $binding->active_unit_key;
                } elseif (! $site || ! $from || ! $this->disjoint($binding, $site, max($from, $binding->starts_on->toDateString()))) {
                    $this->conflict();
                }
            }
        }
        // Preserve existing claims. New exact-hostname claims fit the original
        // index width and cannot collide across credentials or reporting purpose.
        $claimKey = $ownedKey ?? ($site ? 'site:'.hash('sha256', $key.'|'.app(SiteGamReportScope::class)->hostname($site->primary_domain)) : $key);
        $legacy = DB::table('site_gam_reporting_unit_claims')->where('unit_key', $key)->first();
        if ($legacy && ! $ownedKey) {
            $model = match ($legacy->binding_type) {
                'SITE_GAM_AD_UNIT' => SiteGamReportBinding::class,
                'SITE_GAM_VIDEO_AD_UNIT' => SiteGamVideoReportBinding::class,
                default => null,
            };
            $owner = $model ? $model::withoutGlobalScopes()->find($legacy->binding_id) : null;
            if (! $owner || ! $site || ! $from || ! $this->disjoint($owner, $site, max($from, $owner->starts_on->toDateString()))) $this->conflict();
        }
        DB::table('site_gam_reporting_unit_claims')->insertOrIgnore([
            'unit_key' => $claimKey, 'binding_type' => $type, 'binding_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $claim = DB::table('site_gam_reporting_unit_claims')->where('unit_key', $claimKey)->lockForUpdate()->first();
        if (! $claim || $claim->binding_type !== $type || $claim->binding_id !== $id) $this->conflict();
        return $claimKey;
    }

    /** Called under the network mutex at import and historical-range admission. */
    public function assertWindow(SiteGamReportBinding $owner, string $from, string $to): void
    {
        foreach ([SiteGamReportBinding::class, SiteGamVideoReportBinding::class, MobileAppReportBinding::class] as $model) {
            $query = $model::withoutGlobalScopes()->where('network_code', $owner->network_code)->where('ad_unit_id', $owner->ad_unit_id)
                ->whereDate('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from));
            if ($model === SiteGamVideoReportBinding::class) $query->whereNull('cancelled_at');
            foreach ($query->with('connection')->get() as $binding) {
                if ($binding->id === $owner->id && $binding->connectionType() === $owner->connectionType()) continue;
                if (! $this->disjoint($binding, $owner->site, max($from, $binding->starts_on->toDateString()))) $this->conflict();
            }
        }
    }

    private function disjoint($binding, Site $site, string $from): bool
    {
        if (! $binding instanceof SiteGamReportBinding || $binding->site_id === $site->id) return false;
        $scope = data_get($binding->connection?->configuration, 'site_report_scope');
        if (! is_array($scope) || ($scope['effective_from'] ?? '9999') > $from) return false;
        try {
            app(SiteGamReportScope::class)->assertCurrent($binding, $scope);
            return $scope['hostname'] !== app(SiteGamReportScope::class)->hostname($site->primary_domain);
        } catch (\RuntimeException) {
            // Missing, legacy, or changed scope remains exclusive until reviewed.
            return false;
        }
    }

    public function hasDisjointFact($fact, Site $site, string $date): bool
    {
        if (! $fact->connection || ! SiteGamReportBinding::isSiteConnection($fact->connection)) return false;
        $model = SiteGamReportBinding::modelForConnection($fact->connection);
        $binding = $model::withoutGlobalScopes()->with('connection')->find($fact->connection->connection_id);
        if (! $binding || ! $this->disjoint($binding, $site, $date)) return false;
        $scope = data_get($binding->connection->configuration, 'site_report_scope');
        $external = $fact->dimension?->external_dimensions ?? [];
        return $binding->report_source_connection_id === $fact->report_source_connection_id
            && $fact->organization_id === $binding->organization_id
            && $fact->dimension?->organization_id === $binding->organization_id
            && $fact->dimension?->site_id === $binding->site_id
            && $fact->dimension?->publisher_id === $binding->site->publisher_id
            && $fact->dimension?->gam_connection_id === $binding->gam_connection_id
            && ($external['gam_ad_unit_id'] ?? null) === $binding->ad_unit_id
            && ($external['gam_report_site'] ?? null) === $scope['hostname']
            && ($external['gam_report_scope'] ?? null) === $scope['fingerprint']
            && ($external['gam_report_basis'] ?? null) === SiteGamReportMetrics::BASIS;
    }

    public function release(SiteGamReportBinding $binding): void
    {
        DB::table('site_gam_reporting_unit_claims')->where('binding_type', $binding->connectionType())
            ->where('binding_id', $binding->id)->delete();
    }

    public function lastOwnedDay(string $network, string $unit): ?string
    {
        $dates = [];
        foreach ([SiteGamReportBinding::class, SiteGamVideoReportBinding::class, \App\Models\MobileAppReportBinding::class] as $model) {
            $dates[] = $model::withoutGlobalScopes()->where('network_code', $network)->where('ad_unit_id', $unit)
                ->whereNotNull('ends_on')->max('ends_on');
        }
        $dates = array_filter($dates);
        return $dates ? max($dates) : null;
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['ad_unit' => 'This network/ad unit overlaps another financial owner. Sharing requires verified, different exact website hostnames; the same website, mobile apps and unscoped sources remain exclusive.']);
    }
}
