<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\SiteGamAdFormatProbe;
use Illuminate\Console\Command;

/** Read-only live check against Google; stores no report data. */
class ProbeSiteGamAdFormats extends Command
{
    protected $signature = 'reporting:probe-ad-formats
        {--site= : Website ID (default: the first website with an active GAM reporting binding)}
        {--date= : Completed day to test, Y-m-d (default: yesterday in the network time zone)}
        {--wait=60 : Seconds to wait for each Google report, 5-180}';

    protected $description = 'Verify live that Google returns Ad Exchange metrics by inventory format for a website (read-only).';

    public function handle(SiteGamAdFormatProbe $probe): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT);
        if ($wait === false || $wait < 5 || $wait > 180) return self::INVALID;
        $siteId = $this->option('site') ?: SiteGamReportBinding::withoutGlobalScopes()->whereNotNull('active_site_id')
            ->whereHas('connection', fn ($q) => $q->where('is_enabled', true))->orderBy('id')->value('site_id');
        $site = $siteId ? Site::withoutGlobalScopes()->find($siteId) : null;
        if (! $site) {
            $this->line(json_encode(['verdict' => 'NOT_CONNECTED', 'message' => 'No website with an active GAM reporting binding was found.']));

            return self::FAILURE;
        }
        $result = $probe->run($site, $this->option('date') ?: null, $wait);
        $this->line(json_encode(['site_id' => $site->id, 'domain' => $site->primary_domain] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return in_array($result['verdict'], ['SUPPORTED'], true) ? self::SUCCESS : self::FAILURE;
    }
}
