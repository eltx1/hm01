<?php

namespace App\Enums;

enum SellerDeclarationStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';

    public function canTransitionTo(self $target): bool
    {
        return $target !== $this;
    }
}
