<?php

namespace App\Services\SupplyChain;

use Illuminate\Support\Facades\DB;

final class SupplyChainIdentityBackfill
{
    public function run(): int
    {
        $updated = 0;
        DB::table('seller_declarations')->whereNull('publisher_id')->chunkById(200, function ($declarations) use (&$updated): void {
            $siteIds = $declarations->pluck('site_id')->filter()->unique()->values();
            $organizationIds = $declarations->pluck('organization_id')->filter()->unique()->values();
            $sitePublishers = DB::table('sites')->whereIn('id', $siteIds)->pluck('publisher_id', 'id');
            $organizationPublishers = DB::table('publishers')->whereIn('organization_id', $organizationIds)->pluck('id', 'organization_id');

            foreach ($declarations as $declaration) {
                $publisherId = $declaration->site_id
                    ? $sitePublishers->get($declaration->site_id)
                    : $organizationPublishers->get($declaration->organization_id);

                if ($publisherId) {
                    $updated += DB::table('seller_declarations')->where('id', $declaration->id)->whereNull('publisher_id')->update([
                        'publisher_id' => $publisherId,
                    ]);
                }
            }
        }, 'id');

        return $updated;
    }
}
