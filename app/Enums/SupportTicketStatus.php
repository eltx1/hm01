<?php

namespace App\Enums;

enum SupportTicketStatus: string
{
    case Open = 'OPEN';
    case PendingHorus = 'PENDING_HORUS';
    case PendingCustomer = 'PENDING_CUSTOMER';
    case Resolved = 'RESOLVED';
    case Closed = 'CLOSED';

    public function terminal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], true);
    }
}
