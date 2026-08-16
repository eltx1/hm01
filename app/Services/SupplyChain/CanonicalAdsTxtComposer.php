<?php

namespace App\Services\SupplyChain;

use App\Services\SupplyChain\Data\CanonicalAdsTxtSource;
use Illuminate\Support\Collection;

final class CanonicalAdsTxtComposer
{
    /** @param iterable<int, CanonicalAdsTxtSource> $sources */
    public function compose(iterable $sources, array $findings = []): array
    {
        $items = collect($sources);
        $resolved = collect();

        foreach ($items->groupBy(fn (CanonicalAdsTxtSource $source): string => $source->identityKey()) as $key => $group) {
            /** @var Collection<int, CanonicalAdsTxtSource> $group */
            $lines = $group->pluck('line')->unique()->values();
            if ($lines->count() > 1) {
                $findings[] = [
                    'code' => 'ADS_TXT_RELATIONSHIP_CONFLICT',
                    'severity' => 'ERROR',
                    'message' => 'The same advertising-system seller identity has conflicting explicit ads.txt authorization values, including relationship or certification authority.',
                    'identity' => base64_encode((string) $key),
                    'sources' => $group->map(fn (CanonicalAdsTxtSource $source): array => $source->provenance())->values()->all(),
                ];
                continue;
            }

            $winner = $group->sortBy('sortKey')->first();
            $resolved->push([
                'record' => $winner->record,
                'declaration' => $winner->declaration,
                'source_type' => $winner->sourceType,
                'source_id' => $winner->sourceId,
                'line' => $winner->line,
                'key' => $winner->identityKey(),
                'sort_key' => $winner->sortKey,
                'provenance' => $group->sortBy('sortKey')->map(fn (CanonicalAdsTxtSource $source): array => $source->provenance())->values()->all(),
            ]);

            if ($group->count() > 1) {
                $findings[] = [
                    'code' => 'DUPLICATE_ADS_TXT_RECORD',
                    'severity' => 'WARNING',
                    'message' => 'Equivalent explicit ads.txt authorizations collapsed to one canonical line while retaining source provenance.',
                    'identity' => base64_encode((string) $key),
                ];
            }
        }

        $resolved = $resolved->sortBy(fn (array $entry): string => strtolower($entry['line']).'|'.$entry['sort_key'])->values();

        return [
            'records' => $resolved->pluck('record')->filter()->values()->all(),
            'entries' => $resolved->all(),
            'lines' => $resolved->pluck('line')->values()->all(),
            'findings' => collect($findings)->unique(fn (array $finding): string => hash('sha256', json_encode($finding)))->values()->all(),
        ];
    }
}
