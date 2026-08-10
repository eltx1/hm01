<?php

namespace App\Enums;

enum NotificationSeverity: string
{
    case Info = 'INFO';
    case Success = 'SUCCESS';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';
}
