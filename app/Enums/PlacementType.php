<?php

namespace App\Enums;

enum PlacementType: string
{
    case Display = 'DISPLAY';
    case Native = 'NATIVE';
    case Video = 'VIDEO';
    case Sticky = 'STICKY';
    case Interstitial = 'INTERSTITIAL';
    case Rewarded = 'REWARDED';
    case Custom = 'CUSTOM';
}
