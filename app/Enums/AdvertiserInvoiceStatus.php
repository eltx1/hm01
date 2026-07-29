<?php

namespace App\Enums;

enum AdvertiserInvoiceStatus: string
{
    case Draft = 'DRAFT';
    case Issued = 'ISSUED';
    case Paid = 'PAID';
    case Void = 'VOID';
    case Overdue = 'OVERDUE';
}
