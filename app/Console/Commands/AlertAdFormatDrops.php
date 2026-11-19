<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Services\Notifications\HorusNotificationService;
use App\Services\Reporting\AdFormatDropDetector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Notifies Horus reporting staff of sharp per-format drops; idempotent per site/format/metric/day. */
class AlertAdFormatDrops extends Command
{
    protected $signature = 'reporting:ad-format-alerts {--date= : Completed day to check (default: yesterday UTC)}';

    protected $description = 'Detect sharp revenue or eCPM drops per website and ad format and notify reporting staff.';

    public function handle(AdFormatDropDetector $detector, HorusNotificationService $notifications): int
    {
        $day = $this->option('date') ?: CarbonImmutable::now('UTC')->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) return self::INVALID;
        $drops = $detector->detect($day);
        $recipients = $notifications->horusRecipients('reporting.admin.view');
        $created = 0;
        foreach ($drops as $drop) {
            $percent = number_format(abs($drop['change_bp']) / 100, 0);
            $created += $notifications->notify($recipients, [
                'category' => NotificationCategory::Operations,
                'type' => 'AD_FORMAT_DROP',
                'severity' => $drop['change_bp'] <= -8000 ? NotificationSeverity::Critical : NotificationSeverity::Warning,
                'title' => $drop['format_label'].' '.($drop['metric'] === 'ecpm' ? 'eCPM' : 'revenue').' down '.$percent.'% · '.$drop['site'],
                'message' => $drop['domain'].' on '.$day.': '.\App\Support\Money::formatMinor($drop['current']).' USD vs '
                    .\App\Support\Money::formatMinor($drop['baseline']).' USD average of the previous '.AdFormatDropDetector::BASELINE_DAYS.' days.',
                'event_key' => 'ad-format-drop:'.$drop['site_id'].':'.$drop['format'].':'.$drop['metric'].':'.$day,
                'related_type' => 'SITE', 'related_id' => $drop['site_id'],
                'action_route' => 'admin.reporting.publishers.ad-formats',
                'action_parameters' => ['publisher' => $drop['publisher_id'], 'site' => $drop['site_id'],
                    'from' => CarbonImmutable::parse($day)->subDays(13)->toDateString(), 'to' => $day],
            ]);
        }
        $this->line(json_encode(['day' => $day, 'drops' => $drops->count(), 'notifications_created' => $created]));

        return self::SUCCESS;
    }
}
