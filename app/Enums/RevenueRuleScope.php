<?php

namespace App\Enums;

enum RevenueRuleScope: string
{
    case Global = 'GLOBAL';
    case Publisher = 'PUBLISHER';
    case Website = 'WEBSITE';
    case DemandSource = 'DEMAND_SOURCE';
    case Campaign = 'CAMPAIGN';

    public function specificity(): int
    {
        return match ($this) {
            self::Campaign => 50,
            self::DemandSource => 40,
            self::Website => 30,
            self::Publisher => 20,
            self::Global => 10,
        };
    }
}
