<?php

namespace App\Observers;

use App\Models\ReconciliationRun;
use App\Services\Notifications\DomainNotificationService;

class ReconciliationRunObserver
{
    public function __construct(private readonly DomainNotificationService $notifications) {}

    public function created(ReconciliationRun $run): void
    {
        $this->notifications->reconciliationChanged($run);
    }

    public function updated(ReconciliationRun $run): void
    {
        if ($run->wasChanged('status')) {
            $this->notifications->reconciliationChanged($run);
        }
    }
}
