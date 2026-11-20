<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Support = 'SUPPORT';
    case Finance = 'FINANCE';
    case Compliance = 'COMPLIANCE';
    case Sites = 'SITES';
    case Operations = 'OPERATIONS';
    case Account = 'ACCOUNT';
    case Reports = 'REPORTS';

    public function label(): string
    {
        return str($this->value)->lower()->headline()->value();
    }

    /** Earnings reports were requested by email; other optional categories stay in-app by default. */
    public function emailByDefault(): bool
    {
        return $this === self::Reports;
    }

    public function mandatory(): bool
    {
        return $this === self::Account;
    }
}
