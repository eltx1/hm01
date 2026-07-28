<?php

namespace App\Services\Gam;

use App\Enums\GamConnectionType;
use App\Enums\ServingMode;
use App\Models\GamConnection;
use App\Models\Site;
use RuntimeException;

final class GamConnectionResolver
{
    public function resolve(Site $site): ?GamConnection
    {
        if ($site->gam_connection_id) {
            $explicit = GamConnection::withoutGlobalScopes()
                ->whereKey($site->gam_connection_id)
                ->where('is_enabled', true)
                ->first();

            if ($explicit) {
                return $explicit;
            }
        }

        return match ($site->serving_mode) {
            ServingMode::HorusGam => GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::HorusGam)
                ->where('is_primary', true)
                ->where('is_enabled', true)
                ->first(),
            ServingMode::McmPartnerGam => GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::McmPartnerGam)
                ->where('is_enabled', true)
                ->orderByDesc('last_successful_sync_at')
                ->first(),
            ServingMode::PublisherGam => GamConnection::withoutGlobalScopes()
                ->where('type', GamConnectionType::PublisherGam)
                ->where('organization_id', $site->organization_id)
                ->where('is_enabled', true)
                ->orderByDesc('last_successful_sync_at')
                ->first(),
            default => null,
        };
    }

    public function requireFor(Site $site): GamConnection
    {
        return $this->resolve($site)
            ?? throw new RuntimeException("No active GAM connection can be resolved for site {$site->id}.");
    }
}
