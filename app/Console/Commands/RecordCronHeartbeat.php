<?php

namespace App\Console\Commands;

use App\Models\CronHeartbeat;
use Illuminate\Console\Command;
use Throwable;

class RecordCronHeartbeat extends Command
{
    protected $signature = 'operations:heartbeat {name=scheduler}';
    protected $description = 'Record a database heartbeat proving cron and Laravel scheduler execution';

    public function handle(): int
    {
        $name = (string) $this->argument('name');
        $started = hrtime(true);
        $record = CronHeartbeat::query()->updateOrCreate(['name' => $name], [
            'status' => 'RUNNING', 'last_started_at' => now(), 'message' => null,
        ]);
        try {
            $record->update([
                'status' => 'HEALTHY',
                'last_completed_at' => now(),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'message' => 'Scheduler heartbeat completed.',
            ]);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $record->update(['status' => 'FAILED', 'last_failed_at' => now(), 'message' => mb_substr($exception->getMessage(), 0, 2000)]);
            report($exception);
            return self::FAILURE;
        }
    }
}
