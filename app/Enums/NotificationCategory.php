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

    public function label(): string
    {
        return str($this->value)->lower()->headline()->value();
    }

    public function mandatory(): bool
    {
        return $this === self::Account;
    }
}
