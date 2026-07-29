<?php

namespace App\Enums;

enum CampaignPricingModel: string
{
    case Cpm = 'CPM';
    case Cpc = 'CPC';
    case Cpv = 'CPV';
    case FixedSponsorship = 'FIXED_SPONSORSHIP';
    case House = 'HOUSE';
    case Bonus = 'BONUS';
}
