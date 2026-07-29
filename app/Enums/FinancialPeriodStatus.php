<?php

namespace App\Enums;

enum FinancialPeriodStatus: string
{
    case Open = 'OPEN';
    case Closing = 'CLOSING';
    case Closed = 'CLOSED';
}
