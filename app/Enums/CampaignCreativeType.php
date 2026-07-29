<?php

namespace App\Enums;

enum CampaignCreativeType: string
{
    case Image = 'IMAGE';
    case Html5 = 'HTML5';
    case ThirdPartyTag = 'THIRD_PARTY_TAG';
    case Native = 'NATIVE';
    case VideoVast = 'VIDEO_VAST';
    case Text = 'TEXT';
    case House = 'HOUSE';
}
