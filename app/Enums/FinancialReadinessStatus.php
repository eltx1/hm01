<?php

namespace App\Enums;

enum FinancialReadinessStatus: string
{
    case Ready = 'READY';
    case EstimateOnly = 'ESTIMATE_ONLY';
    case NotConfigured = 'NOT_CONFIGURED';
    case Stale = 'STALE';
    case Failed = 'FAILED';
    case CurrencyMismatch = 'CURRENCY_MISMATCH';
    case ReconciliationRequired = 'RECONCILIATION_REQUIRED';
}
