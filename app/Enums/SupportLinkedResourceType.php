<?php

namespace App\Enums;

enum SupportLinkedResourceType: string
{
    case Site = 'SITE';
    case Contract = 'CONTRACT';
    case Statement = 'STATEMENT';
    case Payment = 'PAYMENT';
    case Campaign = 'CAMPAIGN';

    public function label(): string
    {
        return str($this->value)->title()->value();
    }
}
