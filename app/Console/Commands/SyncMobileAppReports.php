<?php

namespace App\Console\Commands;

use App\Models\MobileAppReportBinding;
use App\Services\MobileApps\MobileAppReportSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncMobileAppReports extends Command
{
    protected $signature = 'reporting:sync-mobile-apps {--app=}';
    protected $description = 'Synchronize exact-app Google reports and finalized publisher earnings.';

    public function handle(MobileAppReportSynchronizer $sync): int
    {
        $failed = false;
        MobileAppReportBinding::withoutGlobalScopes()->whereHas('connection', fn ($q) => $q->where('is_enabled', true))
            ->when($this->option('app'), fn ($q, $id) => $q->where('mobile_app_id', $id))->each(function ($binding) use ($sync, &$failed) {
                $lock = Cache::lock('mobile-app-sync:'.$binding->id, 600);
                if (! $lock->get()) return;
                try {
                    foreach ($sync->sync($binding) as $job) {
                        $this->line($binding->mobile_app_id.': '.$job->status->value);
                        $failed = $failed || $job->status->value === 'FAILED';
                    }
                } catch (\Throwable $e) {
                    report($e); $failed = true;
                    $this->error($binding->mobile_app_id.': synchronization will retry.');
                } finally { $lock->release(); }
            });
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
