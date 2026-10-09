<?php

namespace App\Console\Commands;

use App\Models\SiteGamReportBinding;
use App\Services\Reporting\SiteGamReportSynchronizer;
use App\Services\Reporting\MainReportSyncLock;
use App\Services\Reporting\MainReportSyncLockException;
use Illuminate\Console\Command;

class SyncSiteGamReports extends Command
{
    protected $signature = 'reporting:sync-site-gam {--site= : Synchronize one website}';

    protected $description = 'Synchronize reporting-only GAM ad units independently of advertising delivery.';

    public function handle(SiteGamReportSynchronizer $sync, MainReportSyncLock $lock): int
    {
        try {
            if (! $lock->acquire()) {
                $this->line('Main reporting is already running; the next scheduled pass will retry.');
                return self::SUCCESS;
            }
            try {
                return $this->synchronize($sync, $lock);
            } finally {
                $lock->release();
            }
        } catch (MainReportSyncLockException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function synchronize(SiteGamReportSynchronizer $sync, MainReportSyncLock $lock): int
    {
        $failed = false;
        SiteGamReportBinding::withoutGlobalScopes()->when($this->option('site'), fn ($q, $id) => $q->where('site_id', $id))
            ->whereHas('connection', fn ($q) => $q->where('is_enabled', true))->each(function ($binding) use ($sync, $lock, &$failed): void {
                $lock->assertOwned();
                try {
                    foreach ($sync->sync($binding) as $job) {
                        $this->line($binding->site_id.': '.$job->status->value.' ('.$job->row_count.' rows)');
                        $failed = $failed || $job->status->value === 'FAILED';
                    }
                } catch (MainReportSyncLockException $exception) {
                    throw $exception;
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->error($binding->site_id.': reporting synchronization will retry.');
                    $failed = true;
                } finally {
                    // Ownership loss stops the whole command, including this finally path.
                    $lock->assertOwned();
                    // Independent SOAP counter still runs if the AdX finance request is pending or failed.
                    try {
                        $result = app(\App\Services\Reporting\SiteGamUnfilledSynchronizer::class)->sync($binding);
                        $this->line('Unit Unfilled: '.$result['status']);
                    } catch (MainReportSyncLockException $exception) {
                        throw $exception;
                    } catch (\Throwable) {
                        $this->line('Unit Unfilled: SOURCE_UNAVAILABLE');
                    }
                }
            });

        $lock->assertOwned();
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
