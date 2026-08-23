<?php

namespace App\Enums;

enum VerificationMethod: string
{
    case AdsTxt = 'ADS_TXT';
    case MetaTag = 'META_TAG';
    case TextFile = 'TEXT_FILE';
    case DnsTxt = 'DNS_TXT';
    case Manual = 'MANUAL';
}
