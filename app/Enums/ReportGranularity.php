<?php

namespace App\Enums;

enum ReportGranularity: string
{
    case Hourly = 'HOURLY';
    case Daily = 'DAILY';
    case Monthly = 'MONTHLY';
}
