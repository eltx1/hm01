<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\Publisher;
use App\Services\Notifications\HorusNotificationService;
use App\Services\Reporting\PublisherEarningsDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily / weekly earnings reports to publisher users through the existing
 * notification pipeline (in-app + email via notifications:deliver-email).
 * Idempotent per publisher, period and day; respects "Reports" preferences.
 */
class SendPublisherEarningsDigests extends Command
{
    protected $signature = 'reporting:send-earnings-digests {--period=daily : daily or weekly} {--date= : Last completed day (default: yesterday UTC)}';

    protected $description = 'Send publisher earnings reports (daily or weekly) by in-app notification and email.';

    public function handle(PublisherEarningsDigest $digests, HorusNotificationService $notifications): int
    {
        $period = $this->option('period');
        if (! in_array($period, ['daily', 'weekly'], true)) return self::INVALID;
        $day = $this->option('date') ?: CarbonImmutable::now('UTC')->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) return self::INVALID;
        $sent = 0;
        $publishers = 0;
        Publisher::withoutGlobalScopes()->orderBy('id')->each(function (Publisher $publisher) use ($digests, $notifications, $period, $day, &$sent, &$publishers): void {
            try {
                $digest = $digests->build($publisher, $period, $day);
            } catch (\Throwable $exception) {
                report($exception);

                return;
            }
            if (! $digest) return;
            $publishers++;
            $sent += $notifications->notify($notifications->organizationRecipients($publisher->organization_id, 'reporting.publisher.view'), [
                'category' => NotificationCategory::Reports,
                'type' => $period === 'weekly' ? 'EARNINGS_REPORT_WEEKLY' : 'EARNINGS_REPORT_DAILY',
                'severity' => NotificationSeverity::Info,
                'title' => $digest['title'], 'message' => $digest['message'],
                'event_key' => 'earnings-report:'.$period.':'.$publisher->id.':'.$day,
                'related_type' => 'PUBLISHER', 'related_id' => $publisher->id,
                'action_route' => 'publisher.reporting.ad-formats', 'action_parameters' => ['from' => $digest['from'], 'to' => $digest['to']],
            ]);
        });
        $this->line(json_encode(['period' => $period, 'day' => $day, 'publishers' => $publishers, 'notifications_created' => $sent]));

        return self::SUCCESS;
    }
}
