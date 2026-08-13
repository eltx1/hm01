<?php

namespace App\Enums;

enum FinancialReportingMethod: string
{
    case Api = 'API';
    case Csv = 'CSV';
    case Manual = 'MANUAL';
    case Estimate = 'ESTIMATE';
}
