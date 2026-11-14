<?php

namespace App\Services\Sites;

use App\Models\Site;

/** Presentation of existing monitor records, not a new health or admission gate. */
final class SiteHealthEvidence
{
    public function forSite(Site $site): array
    {
        return $this->summarize($site->status->value, $site->monetizationHealthStates->map(fn ($state) => [
            'status' => $state->status,
            'observed_at' => $state->observed_at?->getTimestamp(),
        ])->all(), now()->getTimestamp());
    }

    public function summarize(string $siteStatus, array $records, int $asOf): array
    {
        $findings = count(array_filter($records, fn (array $record) => ($record['status'] ?? null) === 'BROKEN'));
        $times = array_column($records, 'observed_at');
        $validTimes = array_filter($times, fn ($time) => is_int($time) && $time <= $asOf && $time > 0);
        $oldest = $validTimes ? min($validTimes) : null;
        $recognized = array_filter($records, fn (array $record) => in_array($record['status'] ?? null, ['HEALTHY', 'BROKEN'], true));
        $state = match (true) {
            $siteStatus !== 'ACTIVE' => 'NOT_MONITORED',
            $records === [] => 'UNKNOWN',
            count($recognized) !== count($records), count($validTimes) !== count($records) => 'UNKNOWN',
            $oldest < $asOf - 3600 => 'STALE',
            $findings > 0 => 'CURRENT_FINDINGS',
            default => 'CURRENT_CLEAR',
        };
        $label = match ($state) {
            'NOT_MONITORED' => 'Not monitored while inactive',
            'UNKNOWN' => 'Current evidence unavailable',
            'STALE' => 'Stored evidence is stale',
            'CURRENT_FINDINGS' => 'Stored checks have findings',
            default => 'No findings in stored checks',
        };

        return ['state' => $state, 'label' => $label, 'findings' => $findings,
            'checks' => count($records), 'oldest_observation_utc' => $oldest ? gmdate('Y-m-d H:i:s', $oldest) : null];
    }
}
