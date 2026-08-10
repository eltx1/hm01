<?php

namespace App\Enums;

enum MonetizationDependency: string
{
    case Critical = 'CRITICAL';
    case Recommended = 'RECOMMENDED';
    case Optional = 'OPTIONAL';
}
