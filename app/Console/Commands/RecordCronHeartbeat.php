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
        $heartbeat = new SystemHeartbeat([
            'key' => (string) $this->argument('key'),
            'status' => 'HEALTHY', 'last_seen_at' => now(),
            'metadata' => ['hostname' => gethostname() ?: null, 'php' => PHP_VERSION],
        ]);
        // One database statement handles simultaneous first writes safely. Use
        // model attributes so JSON/date casts match the existing heartbeat format.
        SystemHeartbeat::query()->upsert([$heartbeat->getAttributes()], ['key'], [
            'status', 'last_seen_at', 'metadata', 'updated_at',
        ]);
        $this->info('Heartbeat recorded.');
        return self::SUCCESS;
    }
}
