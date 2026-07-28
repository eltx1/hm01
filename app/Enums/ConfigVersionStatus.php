<?php

namespace App\Enums;

enum ConfigVersionStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case RolledBack = 'ROLLED_BACK';
}
