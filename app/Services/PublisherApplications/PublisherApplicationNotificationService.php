<?php

namespace App\Services\PublisherApplications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\PublisherApplication;
use App\Services\Notifications\HorusNotificationService;

final class PublisherApplicationNotificationService
{
    public function __construct(private readonly HorusNotificationService $notifications) {}

    public function submitted(PublisherApplication $application): int
    {
        return $this->notifications->notify($this->notifications->horusRecipients('publisher_applications.review'), [
            'category' => NotificationCategory::Account,
            'type' => 'PUBLISHER_APPLICATION_SUBMITTED',
            'severity' => NotificationSeverity::Info,
            'title' => 'Publisher application awaiting review',
            'message' => $application->publisher->display_name.' submitted application revision '.$application->current_revision.'.',
            'event_key' => 'publisher-application:submitted:'.$application->id.':'.$application->current_revision,
            'related_type' => 'PUBLISHER_APPLICATION',
            'related_id' => $application->id,
            'action_route' => 'admin.publisher-applications.show',
            'action_parameters' => ['application' => $application->id],
        ]);
    }

    public function informationRequested(PublisherApplication $application): int
    {
        return $this->applicant($application, 'PUBLISHER_APPLICATION_MORE_INFO', NotificationSeverity::Warning,
            'Additional information requested',
            'Horus Media requested more information for your Publisher application. Sign in to review the request and resubmit.',
            'more-info:'.$application->updated_at?->getTimestamp());
    }

    public function approved(PublisherApplication $application): int
    {
        return $this->applicant($application, 'PUBLISHER_APPLICATION_APPROVED', NotificationSeverity::Success,
            'Publisher application approved',
            'Your application was approved. Continue into the existing Horus Publisher onboarding flow.',
            'approved');
    }

    public function rejected(PublisherApplication $application): int
    {
        return $this->applicant($application, 'PUBLISHER_APPLICATION_REJECTED', NotificationSeverity::Warning,
            'Publisher application decision available',
            'Horus Media completed its review. Sign in to view your application status.',
            'rejected');
    }

    private function applicant(
        PublisherApplication $application,
        string $type,
        NotificationSeverity $severity,
        string $title,
        string $message,
        string $event,
    ): int {
        return $this->notifications->notify([$application->applicant], [
            'category' => NotificationCategory::Account,
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'event_key' => 'publisher-application:'.$application->id.':'.$event,
            'related_type' => 'PUBLISHER_APPLICATION',
            'related_id' => $application->id,
            'action_route' => 'publisher-application.show',
            'action_parameters' => [],
        ]);
    }
}
