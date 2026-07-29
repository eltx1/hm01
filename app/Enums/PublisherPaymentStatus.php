<?php

namespace App\Enums;

enum PublisherPaymentStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Processing = 'PROCESSING';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
