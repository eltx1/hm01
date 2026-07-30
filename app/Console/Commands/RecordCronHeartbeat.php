<?php

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;

class RecordCronHeartbeat extends Command
{
    protected $signature = 'operations:heartbeat {key=scheduler}';
    protected $description = 'Record a database-backed Horus Media cron heartbeat.';

    public function handle(): int
    {
        SystemHeartbeat::query()->updateOrCreate(['key' => (string) $this->argument('key')], [
            'status' => 'HEALTHY', 'last_seen_at' => now(),
            'metadata' => ['hostname' => gethostname() ?: null, 'php' => PHP_VERSION],
        ]);
        $this->info('Heartbeat recorded.');
        return self::SUCCESS;
    }
}
