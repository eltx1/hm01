<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class DataRetentionManager
{
    /**
     * @return array{
     *   started_at:string,
     *   finished_at:string,
     *   mode:string,
     *   chunk_size:int,
     *   datasets:array<string,array<string,mixed>>,
     *   failed:int
     * }
     */
    public function run(bool $execute = false): array
    {
        $startedAt = CarbonImmutable::now('UTC');
        $chunkSize = max(1, min(5000, (int) config('data-retention.chunk_size', 500)));
        $results = [];
        $failed = 0;

        foreach ((array) config('data-retention.datasets', []) as $dataset => $policy) {
            try {
                $cutoff = $this->cutoffFor((string) $dataset, (array) $policy, $startedAt);
                $query = $this->eligibleQuery((string) $dataset, $cutoff);
                $eligible = (clone $query)->count();
                $deleted = $execute ? $this->deleteInChunks($query, $chunkSize) : 0;

                $results[(string) $dataset] = [
                    'category' => (string) ($policy['category'] ?? 'UNCLASSIFIED'),
                    'table' => (string) ($policy['table'] ?? ''),
                    'cutoff' => $cutoff->toIso8601String(),
                    'eligible' => $eligible,
                    'deleted' => $deleted,
                    'status' => 'OK',
                ];
            } catch (Throwable $exception) {
                $failed++;
                $results[(string) $dataset] = [
                    'category' => (string) ($policy['category'] ?? 'UNCLASSIFIED'),
                    'table' => (string) ($policy['table'] ?? ''),
                    'cutoff' => null,
                    'eligible' => 0,
                    'deleted' => 0,
                    'status' => 'FAILED',
                    // Exception class is operational metadata; messages may contain private data.
                    'error_type' => $exception::class,
                ];
            }
        }

        $finishedAt = CarbonImmutable::now('UTC');

        return [
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'mode' => $execute ? 'EXECUTE' : 'DRY_RUN',
            'chunk_size' => $chunkSize,
            'datasets' => $results,
            'failed' => $failed,
        ];
    }

    private function cutoffFor(string $dataset, array $policy, CarbonImmutable $now): CarbonImmutable
    {
        return match ($dataset) {
            'privacy_diagnostic_tokens' => $now->subDays(max(1, (int) ($policy['expired_grace_days'] ?? 30))),
            'synthetic_probe_results',
            'privacy_diagnostic_evidence',
            'expired_user_invitations',
            'completed_job_batches' => $now->subDays(max(1, (int) ($policy['retention_days'] ?? 1))),
            default => throw new RuntimeException('Dataset is not in the data-retention execution allowlist.'),
        };
    }

    private function eligibleQuery(string $dataset, CarbonImmutable $cutoff): Builder
    {
        return match ($dataset) {
            'synthetic_probe_results' => DB::table('synthetic_probe_results')
                ->where('observed_at', '<', $cutoff),

            'privacy_diagnostic_evidence' => DB::table('privacy_diagnostic_evidence')
                ->where('observed_at', '<', $cutoff),

            'privacy_diagnostic_tokens' => DB::table('privacy_diagnostic_tokens')
                ->where('expires_at', '<', $cutoff),

            'expired_user_invitations' => DB::table('user_invitations')
                ->whereNull('accepted_at')
                ->where('expires_at', '<', $cutoff),

            'completed_job_batches' => DB::table('job_batches')
                ->where('created_at', '<', $cutoff->timestamp)
                ->where(function (Builder $query): void {
                    $query->whereNotNull('finished_at')->orWhereNotNull('cancelled_at');
                }),

            default => throw new RuntimeException('Dataset is not in the data-retention execution allowlist.'),
        };
    }

    private function deleteInChunks(Builder $eligibleQuery, int $chunkSize): int
    {
        $deleted = 0;

        while (true) {
            $ids = (clone $eligibleQuery)
                ->select('id')
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Reapply the eligibility predicate at delete time so a row that changed
            // after selection cannot be removed merely because its id was observed.
            $deleted += (clone $eligibleQuery)
                ->whereIn('id', $ids->all())
                ->delete();
        }

        return $deleted;
    }
}
