<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit-logs:prune {--days= : Override the configured audit retention period in days}';

    protected $description = 'Prune audit records older than the configured retention period';

    public function handle(): int
    {
        $configuredDays = max(1, (int) config('data-retention.audit_logs_days', 2555));
        $days = $this->option('days') === null
            ? $configuredDays
            : max(1, (int) $this->option('days'));

        $deleted = AuditLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} audit log record(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
