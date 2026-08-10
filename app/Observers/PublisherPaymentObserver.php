<?php

namespace App\Observers;

use App\Models\PublisherPayment;
use App\Services\Notifications\DomainNotificationService;

class PublisherPaymentObserver
{
    public function __construct(private readonly DomainNotificationService $notifications) {}

    public function updated(PublisherPayment $payment): void
    {
        if ($payment->wasChanged('status')) {
            $this->notifications->payoutChanged($payment);
        }
    }
}
