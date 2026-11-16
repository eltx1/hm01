<?php

namespace App\Services\Sites;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\HorusNotification;
use App\Models\Site;
use App\Services\Audit\AuditRecorder;
use App\Services\Notifications\HorusNotificationService;
use Illuminate\Support\Facades\DB;

final class PublisherSetupReminder
{
    public function __construct(private readonly HorusNotificationService $notifications, private readonly AuditRecorder $audit) {}

    public function run(bool $apply = false): array
    {
        $eligible = 0;
        $created = 0;
        Site::withoutGlobalScopes()->whereNull('deleted_at')->whereIn('status', ['DRAFT', 'PENDING_VERIFICATION'])
            ->where('updated_at', '<=', now()->subDays(7))
            ->whereHas('organization', fn ($o) => $o->where('type', 'PUBLISHER')->where('status', 'ACTIVE'))
            ->whereHas('publisher', fn ($p) => $p->where('status', 'ACTIVE')->whereColumn('publishers.organization_id', 'sites.organization_id'))
            ->orderBy('id')->chunkById(100, function ($sites) use ($apply, &$eligible, &$created): void {
                foreach ($sites as $candidate) {
                    $result = DB::transaction(function () use ($candidate, $apply): array {
                        $site = Site::withoutGlobalScopes()->whereKey($candidate->id)->lockForUpdate()->first();
                        if (! $site || $site->deleted_at !== null || ! $site->organization
                            || $site->organization->type->value !== 'PUBLISHER' || $site->organization->status->value !== 'ACTIVE'
                            || ! in_array($site->status->value, ['DRAFT', 'PENDING_VERIFICATION'], true)
                            || $site->updated_at->gt(now()->subDays(7)) || ! $site->publisher
                            || $site->publisher->status->value !== 'ACTIVE'
                            || $site->publisher->organization_id !== $site->organization_id) return [0, 0];
                        $recent = HorusNotification::query()->where('type', 'PUBLISHER_SETUP_REMINDER')->where('related_id', $site->id)
                            ->where('created_at', '>', now()->subDays(7))->pluck('recipient_id')->all();
                        $recipients = $this->notifications->organizationRecipients($site->organization_id, 'publisher.ads_txt.verify_own')
                            ->filter(function ($user) use ($recent): bool {
                                $preference = $this->notifications->preference($user, NotificationCategory::Sites);
                                return $user->email_verified_at !== null && ! in_array($user->id, $recent, true)
                                    && ($preference['in_app_enabled'] || $preference['email_enabled']);
                            });
                        if (! $apply || $recipients->isEmpty()) return [$recipients->count(), 0];
                        $guidance = (new PublisherSetupGuidance)->describe($site->status->value);
                        $count = $this->notifications->notify($recipients, [
                            'category' => NotificationCategory::Sites, 'severity' => NotificationSeverity::Info,
                            'type' => 'PUBLISHER_SETUP_REMINDER', 'title' => $guidance['title'],
                            'message' => $guidance['detail'], 'event_key' => 'site:setup-reminder:'.$site->id.':'.now()->utc()->format('o-W'),
                            'related_type' => 'SITE', 'related_id' => $site->id,
                            'action_route' => 'publisher.sites.show', 'action_parameters' => ['site' => $site->id],
                        ]);
                        if ($count > 0) $this->audit->record('site.setup.reminder.queued', $site->organization_id, auditable: $site,
                            newValues: ['status' => $site->status->value, 'recipient_count' => $count]);

                        return [$recipients->count(), $count];
                    });
                    $eligible += $result[0];
                    $created += $result[1];
                }
            });

        return ['eligible_recipients' => $eligible, 'created' => $created, 'applied' => $apply];
    }
}
