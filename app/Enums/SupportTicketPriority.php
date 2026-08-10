<?php

namespace App\Enums;

enum SupportTicketPriority: string
{
    case Low = 'LOW';
    case Normal = 'NORMAL';
    case High = 'HIGH';
    case Urgent = 'URGENT';
}
