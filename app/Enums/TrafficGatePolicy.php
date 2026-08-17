<?php

namespace App\Enums;

enum TrafficGatePolicy: string
{
    case Strict = 'STRICT';
    case Balanced = 'BALANCED';
    case Permissive = 'PERMISSIVE';
}
