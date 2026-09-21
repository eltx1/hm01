<?php

namespace App\Console\Commands;

use App\Models\SiteGamReportBinding;
use App\Services\Reporting\SiteGamReportSynchronizer;
use Illuminate\Console\Command;

class SyncSiteGamReports extends Command
{
    protected $signature = 'reporting:sync-site-gam {--site= : Synchronize one website}';

    protected $description = 'Synchronize reporting-only GAM ad units independently of advertising delivery.';

    public function handle(SiteGamReportSynchronizer $sync): int
    {
        $failed = false;
        SiteGamReportBinding::withoutGlobalScopes()->when($this->option('site'), fn ($q, $id) => $q->where('site_id', $id))
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
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
