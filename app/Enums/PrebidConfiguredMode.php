<?php

namespace App\Enums;

enum PrebidConfiguredMode: string
{
    case Auto = 'AUTO';
    case GamBridge = 'GAM_BRIDGE';
    case Standalone = 'STANDALONE';
}
