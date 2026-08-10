<?php

namespace App\Enums;

enum PublisherInvoiceStatus: string
{
    case NotRequired = 'NOT_REQUIRED';
    case Required = 'REQUIRED';
    case Received = 'RECEIVED';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
}
