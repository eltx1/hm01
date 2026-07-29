<?php

namespace App\Enums;

enum DemandNetworkCode: string
{
    case Mgid = 'MGID';
    case Taboola = 'TABOOLA';
    case Speakol = 'SPEAKOL';
    case Outbrain = 'OUTBRAIN';
    case CustomNative = 'CUSTOM_NATIVE';
    case CustomDisplay = 'CUSTOM_DISPLAY';
    case CustomThirdPartyTag = 'CUSTOM_THIRD_PARTY_TAG';
}
