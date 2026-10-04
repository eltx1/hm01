<?php

namespace App\Services\Reporting;

use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Call inside binding transaction. Unique physical key is the cross-purpose mutex. */
final class SiteGamUnitClaims
{
    public function reserve(string $key, string $type, string $id): void
    {
        foreach ([SiteGamReportBinding::class, SiteGamVideoReportBinding::class] as $model) {
            if ($model::withoutGlobalScopes()->where('active_unit_key', $key)->where('id', '!=', $id)->exists()) {
                $this->conflict();
            }
        }
        DB::table('site_gam_reporting_unit_claims')->insertOrIgnore([
            'unit_key' => $key, 'binding_type' => $type, 'binding_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $claim = DB::table('site_gam_reporting_unit_claims')->where('unit_key', $key)->lockForUpdate()->first();
        if (! $claim || $claim->binding_type !== $type || $claim->binding_id !== $id) $this->conflict();
    }

    public function release(SiteGamReportBinding $binding): void
    {
        DB::table('site_gam_reporting_unit_claims')->where('binding_type', $binding->connectionType())
            ->where('binding_id', $binding->id)->delete();
    }

    public function lastOwnedDay(string $network, string $unit): ?string
    {
        $dates = [];
        foreach ([SiteGamReportBinding::class, SiteGamVideoReportBinding::class] as $model) {
            $dates[] = $model::withoutGlobalScopes()->where('network_code', $network)->where('ad_unit_id', $unit)
                ->whereNotNull('ends_on')->max('ends_on');
        }
        $dates = array_filter($dates);
        return $dates ? max($dates) : null;
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['ad_unit' => 'This network/ad unit already has a primary or Video financial owner. Choose a distinct unit to prevent duplicate publisher earnings.']);
    }
}
