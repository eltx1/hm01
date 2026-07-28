<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case Signed = 'SIGNED';
    case Active = 'ACTIVE';
    case Expired = 'EXPIRED';
    case Terminated = 'TERMINATED';
}
