<?php

namespace App\Enums;

enum DemandIntegrationMode: string
{
    case DirectJs = 'DIRECT_JS';
    case GamThirdPartyCreative = 'GAM_THIRD_PARTY_CREATIVE';
    case GamLineItem = 'GAM_LINE_ITEM';
    case ManualTag = 'MANUAL_TAG';
    case ApiIntegration = 'API_INTEGRATION';
}
