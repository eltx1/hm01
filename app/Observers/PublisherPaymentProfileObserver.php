<?php

namespace App\Observers;

use App\Models\PublisherPaymentProfile;
use App\Services\Notifications\DomainNotificationService;

class PublisherPaymentProfileObserver
{
    public function __construct(private readonly DomainNotificationService $notifications) {}

    public function created(PublisherPaymentProfile $profile): void
    {
        $this->notifications->paymentProfileChanged($profile);
    }

    public function updated(PublisherPaymentProfile $profile): void
    {
        if ($profile->wasChanged('verification_status')) {
            $this->notifications->paymentProfileChanged($profile);
        }
    }
}
