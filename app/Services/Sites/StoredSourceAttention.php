<?php

namespace App\Services\Sites;

use Illuminate\Database\Eloquent\Builder;

/** Latest stored source evidence only. Never fetches publisher/provider resources. */
final class StoredSourceAttention
{
    public static function adsTxt(Builder $query): Builder
    {
        return $query->where('method', 'ADS_TXT')
            ->whereHas('domain', fn ($domain) => $domain->where('is_primary', true)
                ->whereColumn('site_domains.site_id', 'site_verifications.site_id')
                ->whereColumn('site_domains.organization_id', 'site_verifications.organization_id')
                ->whereExists(fn ($site) => $site->selectRaw('1')->from('sites')
                    ->whereColumn('sites.id', 'site_domains.site_id')
                    ->whereColumn('sites.organization_id', 'site_domains.organization_id')
                    ->whereColumn('sites.primary_domain', 'site_domains.domain')->whereNull('sites.deleted_at')))
            ->where('site_verifications.id', '=', function ($latest) {
                $latest->select('newer.id')->from('site_verifications as newer')
                    ->whereColumn('newer.site_id', 'site_verifications.site_id')
                    ->whereColumn('newer.organization_id', 'site_verifications.organization_id')
                    ->whereColumn('newer.site_domain_id', 'site_verifications.site_domain_id')
                    ->where('newer.method', 'ADS_TXT')->orderByDesc('newer.attempted_at')
                    ->orderByDesc('newer.created_at')->orderByDesc('newer.id')->limit(1);
            });
    }

    public static function probes(Builder $query): Builder
    {
        return $query->where('environment', 'PRODUCTION')
            ->whereExists(fn ($site) => $site->selectRaw('1')->from('sites')
                ->whereColumn('sites.id', 'synthetic_probe_results.site_id')
                ->whereColumn('sites.organization_id', 'synthetic_probe_results.organization_id')->whereNull('sites.deleted_at'))
            ->where('synthetic_probe_results.id', '=', function ($latest) {
                $latest->select('newer.id')->from('synthetic_probe_results as newer')
                    ->whereColumn('newer.site_id', 'synthetic_probe_results.site_id')
                    ->whereColumn('newer.organization_id', 'synthetic_probe_results.organization_id')
                    ->whereColumn('newer.probe', 'synthetic_probe_results.probe')
                    ->where('newer.environment', 'PRODUCTION')->orderByDesc('newer.observed_at')
                    ->orderByDesc('newer.created_at')->orderByDesc('newer.id')->limit(1);
            });
    }
}
