<?php

namespace App\Enums;

enum CampaignNetworkStatus: string
{
    case Pending = 'PENDING';
    case DryRun = 'DRY_RUN';
    case Deploying = 'DEPLOYING';
    case Deployed = 'DEPLOYED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Drifted = 'DRIFTED';
}
