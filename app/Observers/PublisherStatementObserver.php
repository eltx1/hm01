<?php

namespace App\Observers;

use App\Models\PublisherStatement;
use App\Services\Notifications\DomainNotificationService;

class PublisherStatementObserver
{
    public function __construct(private readonly DomainNotificationService $notifications) {}

    public function created(PublisherStatement $statement): void
    {
        if ($statement->finalized_at) {
            $this->notifications->statementFinalized($statement);
        }
    }

    public function updated(PublisherStatement $statement): void
    {
        if ($statement->finalized_at && ($statement->wasChanged('finalized_at') || $statement->wasChanged('snapshot_hash'))) {
            $this->notifications->statementFinalized($statement);
        }
    }
}
