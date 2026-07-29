<?php

namespace App\Enums;

enum PublisherStatementStatus: string
{
    case Draft = 'DRAFT';
    case Finalized = 'FINALIZED';
    case BelowThreshold = 'BELOW_THRESHOLD';
    case PendingInvoice = 'PENDING_INVOICE';
    case Payable = 'PAYABLE';
    case PartiallyPaid = 'PARTIALLY_PAID';
    case Paid = 'PAID';
    case CarriedForward = 'CARRIED_FORWARD';
}
