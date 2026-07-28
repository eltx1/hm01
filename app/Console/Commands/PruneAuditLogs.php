<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit-logs:prune {--days=2555 : Retention period in days}';

    protected $description = 'Prune audit records older than the configured retention period';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = AuditLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} audit log record(s).");

        return self::SUCCESS;
    }
}
