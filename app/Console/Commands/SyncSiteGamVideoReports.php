<?php

namespace App\Console\Commands;

use App\Models\SiteGamVideoReportBinding;
use App\Services\Reporting\SiteGamReportSynchronizer;
use Illuminate\Console\Command;

class SyncSiteGamVideoReports extends Command
{
    protected $signature = 'reporting:sync-site-gam-video {--site= : Synchronize one website}';

    protected $description = 'Synchronize optional Video GAM finances and independent unit Unfilled.';

    public function handle(SiteGamReportSynchronizer $sync): int
    {
        $failed = false;
        SiteGamVideoReportBinding::withoutGlobalScopes()->when($this->option('site'), fn ($q, $id) => $q->where('site_id', $id))
            ->whereHas('connection', fn ($q) => $q->where('is_enabled', true))->each(function ($binding) use ($sync, &$failed): void {
                try {
                    foreach ($sync->sync($binding) as $job) {
                        $this->line($binding->site_id.': '.$job->status->value.' ('.$job->row_count.' rows)');
                        $failed = $failed || $job->status->value === 'FAILED';
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                    $this->error($binding->site_id.': reporting synchronization will retry.');
                    $failed = true;
                } finally {
                    // Independent SOAP counter still runs if the AdX finance request is pending or failed.
                    try {
                        $result = app(\App\Services\Reporting\SiteGamUnfilledSynchronizer::class)->sync($binding);
                        $this->line('Unit Unfilled: '.$result['status']);
                    } catch (\Throwable) {
                        $this->line('Unit Unfilled: SOURCE_UNAVAILABLE');
                    }
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
