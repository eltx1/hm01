<?php

namespace App\Enums;

enum ReportFinality: string
{
    case Estimated = 'ESTIMATED';
    case Finalized = 'FINALIZED';
}
