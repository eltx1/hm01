<?php

namespace App\Enums;

enum SellerType: string
{
    case Publisher = 'PUBLISHER';
    case Intermediary = 'INTERMEDIARY';
    case Both = 'BOTH';

    public function ownsInventory(): bool
    {
        return $this !== self::Intermediary;
    }

    public function expectedAdsTxtRelationship(): string
    {
        return $this === self::Intermediary ? 'RESELLER' : 'DIRECT';
    }
}
