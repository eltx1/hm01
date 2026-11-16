<?php

namespace App\Services\Inventory;

use App\Services\StaticDelivery\CanonicalJson;

/** Compare recipes without returning tags, provider identifiers or script URLs. */
final class RollbackDemandEvidence
{
    public function compare(array $historical, array $current): array
    {
        $matched = 0;
        $unverified = 0;
        $managed = 0;
        $canonical = new CanonicalJson;
        foreach (($historical['placements'] ?? []) as $code => $placement) {
            foreach (($placement['candidates'] ?? []) as $candidate) {
                if (($candidate['gamManaged'] ?? null) === true) { $managed++; continue; }
                if (! is_array($candidate) || ! is_array($candidate['tag'] ?? null)) { $unverified++; continue; }
                $found = false;
                foreach (($current['placements'][$code]['candidates'] ?? []) as $eligible) {
                    if (($eligible['gamManaged'] ?? null) === false
                        && ($eligible['network'] ?? null) === ($candidate['network'] ?? null)
                        && ($eligible['mode'] ?? null) === ($candidate['mode'] ?? null)
                        && is_array($eligible['tag'] ?? null)
                        && $canonical->encode($eligible['tag']) === $canonical->encode($candidate['tag'])) {
                        $found = true;
                        break;
                    }
                }
                if ($found) $matched++; else $unverified++;
            }
        }

        return ['matched' => $matched, 'unverified' => $unverified, 'gam_managed_not_checked' => $managed];
    }
}
