<?php

namespace App\Enums;

enum PrebidPriceGranularity: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Auto = 'AUTO';
    case Dense = 'DENSE';
    case Custom = 'CUSTOM';
}
