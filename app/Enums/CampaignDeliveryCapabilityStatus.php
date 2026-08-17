<?php

namespace App\Enums;

enum CampaignDeliveryCapabilityStatus: string
{
    case Available = 'AVAILABLE';
    case DraftOnly = 'DRAFT_ONLY';
    case NoGamBackend = 'NO_GAM_BACKEND';
    case GamConnectionDisabled = 'GAM_CONNECTION_DISABLED';
    case GamOperationallyDisabled = 'GAM_OPERATIONALLY_DISABLED';
    case GamConnectionUnhealthy = 'GAM_CONNECTION_UNHEALTHY';
    case TargetInventoryUnavailable = 'TARGET_INVENTORY_UNAVAILABLE';
    case RemoteMappingIncomplete = 'REMOTE_MAPPING_INCOMPLETE';
    case CampaignFeatureDisabled = 'CAMPAIGN_FEATURE_DISABLED';
    case ConfigurationIncomplete = 'CONFIGURATION_INCOMPLETE';
}
