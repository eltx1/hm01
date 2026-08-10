<?php

namespace App\Enums;

enum SupportTicketCategory: string
{
    case Technical = 'TECHNICAL';
    case Monetization = 'MONETIZATION';
    case AdsServing = 'ADS_SERVING';
    case RevenueReporting = 'REVENUE_REPORTING';
    case Payments = 'PAYMENTS';
    case AdsTxtCompliance = 'ADS_TXT_COMPLIANCE';
    case WebsiteApproval = 'WEBSITE_APPROVAL';
    case Contracts = 'CONTRACTS';
    case Account = 'ACCOUNT';
    case Campaigns = 'CAMPAIGNS';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::AdsServing => 'Ads / Serving',
            self::RevenueReporting => 'Revenue & Reporting',
            self::AdsTxtCompliance => 'Ads.txt / Compliance',
            self::WebsiteApproval => 'Website Approval',
            default => str($this->value)->replace('_', ' ')->title()->value(),
        };
    }
}
