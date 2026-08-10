<?php

namespace App\Console\Commands;

use App\Services\Support\SupportSlaMonitor;
use Illuminate\Console\Command;

class MonitorSupportSla extends Command
{
    protected $signature = 'support:sla-monitor {--limit=500}';

    protected $description = 'Emit idempotent Support SLA warning and breach notifications';

    public function handle(SupportSlaMonitor $monitor): int
    {
        $count = $monitor->run((int) $this->option('limit'));
        $this->info("Created {$count} SLA notification(s).");

        return self::SUCCESS;
    }
}
