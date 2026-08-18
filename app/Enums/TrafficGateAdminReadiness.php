<?php

namespace App\Enums;

enum TrafficGateAdminReadiness: string
{
    case Ready = 'READY';
    case SitekeyMissing = 'SITEKEY_MISSING';
    case GateOriginInvalid = 'GATE_ORIGIN_INVALID';
    case GateAssetNotConfigured = 'GATE_ASSET_NOT_CONFIGURED';
    case InvalidTiming = 'INVALID_TIMING';
}
