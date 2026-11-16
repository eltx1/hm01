<?php

namespace App\Services\Sites;

use Illuminate\Database\Eloquent\Builder;

/** Latest stored source evidence only. Never fetches publisher/provider resources. */
final class StoredSourceAttention
{
    public const INSTALLATION_CODES = ['CORRECT_TAG_FOUND', 'UNREADABLE_HTML', 'MULTIPLE_LOADER_TAGS', 'WRONG_SITE_KEY',
        'MISSING_SITE_KEY', 'LOADER_TAG_NOT_FOUND', 'CHECK_TRANSPORT_UNAVAILABLE', 'CHECK_TIMEOUT', 'UNAUTHORIZED_HOST',
        'UNSAFE_REDIRECT', 'REDIRECT_LIMIT', 'HTTP_NOT_SUCCESSFUL', 'NOT_HTML', 'PAGE_TOO_LARGE', 'FETCH_UNAVAILABLE_OR_UNSAFE'];

    public static function installation(Builder $query): Builder
    {
        return $query->where('event', 'site.installation_check.completed')
            ->where('auditable_type', (new \App\Models\Site)->getMorphClass())
            ->where('new_values->checked_primary', true)
            ->whereExists(fn ($site) => $site->selectRaw('1')->from('sites')
                ->whereColumn('sites.id', 'audit_logs.auditable_id')->whereColumn('sites.organization_id', 'audit_logs.organization_id')
                ->whereColumn('sites.primary_domain', 'audit_logs.new_values->primary_domain')
                ->whereColumn('sites.public_key', 'audit_logs.new_values->site_key')->whereNull('sites.deleted_at'))
            ->where('audit_logs.id', '=', function ($latest) {
                $latest->select('newer.id')->from('audit_logs as newer')
                    ->whereColumn('newer.auditable_id', 'audit_logs.auditable_id')->whereColumn('newer.auditable_type', 'audit_logs.auditable_type')
                    ->whereColumn('newer.organization_id', 'audit_logs.organization_id')->whereColumn('newer.event', 'audit_logs.event')
                    ->where('newer.new_values->checked_primary', true)
                    ->whereColumn('newer.new_values->primary_domain', 'audit_logs.new_values->primary_domain')
                    ->whereColumn('newer.new_values->site_key', 'audit_logs.new_values->site_key')
                    ->orderByDesc('newer.created_at')->orderByDesc('newer.id')->limit(1);
            });
    }

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
