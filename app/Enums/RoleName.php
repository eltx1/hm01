<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case OperationsAdmin = 'OPERATIONS_ADMIN';
    case AdOpsAdmin = 'AD_OPS_ADMIN';
    case FinanceAdmin = 'FINANCE_ADMIN';
    case SupportAgent = 'SUPPORT_AGENT';
    case PublisherAdmin = 'PUBLISHER_ADMIN';
    case PublisherViewer = 'PUBLISHER_VIEWER';
    case AdvertiserAdmin = 'ADVERTISER_ADMIN';
    case AdvertiserViewer = 'ADVERTISER_VIEWER';
    case PartnerAdmin = 'PARTNER_ADMIN';
    case PartnerViewer = 'PARTNER_VIEWER';
}
