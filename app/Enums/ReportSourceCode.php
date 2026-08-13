<?php

namespace App\Enums;

enum ReportSourceCode: string
{
    case HorusGam = 'HORUS_GAM';
    case McmPartnerGam = 'MCM_PARTNER_GAM';
    case PublisherGam = 'PUBLISHER_GAM';
    case PrebidEstimates = 'PREBID_ESTIMATES';
    case Mgid = 'MGID';
    case Taboola = 'TABOOLA';
    case Speakol = 'SPEAKOL';
    case Outbrain = 'OUTBRAIN';
    case ExoClick = 'EXOCLICK';
    case OneTag = 'ONETAG';
    case CustomCsv = 'CUSTOM_CSV';
    case ManualAdjustment = 'MANUAL_ADJUSTMENT';
}
