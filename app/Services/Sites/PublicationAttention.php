<?php

namespace App\Services\Sites;

use Illuminate\Database\Eloquent\Builder;

/** Stored delivery evidence only; never starts a retry or affects admission. */
final class PublicationAttention
{
    public static function latest(Builder $query): Builder
    {
        return $query->where('environment', 'PRODUCTION')
            ->whereHas('siteConfig', fn ($config) => $config
                ->whereColumn('site_configs.site_id', 'config_versions.site_id')
                ->whereColumn('site_configs.organization_id', 'config_versions.organization_id'))
            ->where('version', function ($latest) {
                $latest->from('config_versions as newer')->selectRaw('MAX(newer.version)')
                    ->whereColumn('newer.site_config_id', 'config_versions.site_config_id')
                    ->whereColumn('newer.site_id', 'config_versions.site_id')
                    ->whereColumn('newer.organization_id', 'config_versions.organization_id')
                    ->where('newer.environment', 'PRODUCTION');
            });
    }

    public static function issues(Builder $query): Builder
    {
        return self::latest($query)->whereNotIn('status', ['SUPERSEDED', 'ROLLED_BACK'])
            ->where(function ($issue) {
                $issue->whereHas('deliveryItem', fn ($delivery) => $delivery->whereIn('status', ['FAILED', 'RETRY_SCHEDULED']))
                    ->orWhere(fn ($failed) => $failed->where('status', 'DELIVERY_FAILED')
                        ->whereDoesntHave('deliveryItem', fn ($delivery) => $delivery
                            ->whereIn('status', ['PENDING', 'BATCHING', 'UPLOADING', 'DEPLOYED', 'SUPERSEDED'])));
            });
    }
}
