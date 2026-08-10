<?php

namespace App\Enums;

enum PublisherPaymentStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Scheduled = 'SCHEDULED';
    case Processing = 'PROCESSING';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Held = 'HELD';
    case Cancelled = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled], true);
    }

    public function reservesBalance(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Approved,
            self::Scheduled,
            self::Processing,
            self::PartiallyPaid,
            self::Held,
        ], true);
    }
}
