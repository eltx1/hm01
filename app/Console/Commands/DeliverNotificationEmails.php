<?php

namespace App\Console\Commands;

use App\Enums\NotificationCategory;
use App\Mail\HorusNotificationMail;
use App\Models\HorusNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class DeliverNotificationEmails extends Command
{
    protected $signature = 'notifications:deliver-email {--limit=50}';

    protected $description = 'Deliver pending Horus notification email without a permanent worker';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $items = HorusNotification::query()->with('recipient')
            ->where('email_requested', true)->whereNull('emailed_at')
            ->where('email_attempts', '<', 3)->oldest()->limit($limit)->get();

        foreach ($items as $item) {
            try {
                $applicationAccountNotification = $item->category === NotificationCategory::Account
                    && $item->recipient?->isPublisherApplicant();
                if (! $item->recipient?->isActive() && ! $applicationAccountNotification) {
                    $item->update(['email_requested' => false]);

                    continue;
                }
                Mail::to($item->recipient)->send(new HorusNotificationMail($item));
                $item->update(['emailed_at' => now(), 'email_failed_at' => null, 'email_attempts' => $item->email_attempts + 1]);
            } catch (\Throwable $exception) {
                report($exception);
                $item->update(['email_failed_at' => now(), 'email_attempts' => $item->email_attempts + 1]);
            }
        }
        $this->info('Processed '.$items->count().' notification email(s).');

        return self::SUCCESS;
    }
}
