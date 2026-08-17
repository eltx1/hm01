<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditRecorder;
use App\Services\Operations\DataRetentionManager;
use Illuminate\Console\Command;
use Throwable;

final class PruneOperationalDataRetention extends Command
{
    protected $signature = 'data-retention:prune
        {--execute : Delete eligible operational records. Without this flag the command is a dry-run preview.}';

    protected $description = 'Preview or execute bounded operational data retention without touching permanent business history';

    public function handle(DataRetentionManager $manager, AuditRecorder $audit): int
    {
        $execute = (bool) $this->option('execute');
        $summary = $manager->run($execute);

        $this->info('Data retention '.$summary['mode'].' started '.$summary['started_at']);
        $this->line('Chunk size: '.$summary['chunk_size']);

        $rows = [];
        foreach ($summary['datasets'] as $dataset => $result) {
            $rows[] = [
                $dataset,
                $result['category'],
                $result['cutoff'] ?? 'n/a',
                (string) $result['eligible'],
                (string) $result['deleted'],
                $result['status'],
            ];
        }

        $this->table(
            ['Dataset', 'Category', 'Cutoff (UTC)', 'Eligible', 'Deleted', 'Status'],
            $rows,
        );
        $this->info('Data retention '.$summary['mode'].' finished '.$summary['finished_at']);

        try {
            $audit->record(
                event: $execute ? 'operations.data_retention_pruned' : 'operations.data_retention_previewed',
                metadata: [
                    'mode' => $summary['mode'],
                    'started_at' => $summary['started_at'],
                    'finished_at' => $summary['finished_at'],
                    'chunk_size' => $summary['chunk_size'],
                    'failed_datasets' => $summary['failed'],
                    'datasets' => collect($summary['datasets'])->map(fn (array $result): array => [
                        'category' => $result['category'],
                        'eligible' => $result['eligible'],
                        'deleted' => $result['deleted'],
                        'status' => $result['status'],
                        'error_type' => $result['error_type'] ?? null,
                    ])->all(),
                ],
            );
        } catch (Throwable $exception) {
            // The command never prints the exception message because it may contain
            // database/private context. A missing audit summary makes the run fail.
            $this->error('Retention operation summary could not be recorded in the audit log.');

            return self::FAILURE;
        }

        if ($summary['failed'] > 0) {
            $this->error($summary['failed'].' retention dataset(s) failed; remaining datasets were isolated and processed independently.');

            return self::FAILURE;
        }

        if (! $execute) {
            $this->comment('Dry-run only: no eligible operational records were deleted. Re-run with --execute to apply the previewed policy.');
        }

        return self::SUCCESS;
    }
}
