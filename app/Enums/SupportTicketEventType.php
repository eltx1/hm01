<?php

namespace App\Enums;

enum SupportTicketEventType: string
{
    case Created = 'CREATED';
    case PublicReply = 'PUBLIC_REPLY';
    case InternalNote = 'INTERNAL_NOTE';
    case AttachmentAdded = 'ATTACHMENT_ADDED';
    case Assigned = 'ASSIGNED';
    case PriorityChanged = 'PRIORITY_CHANGED';
    case StatusChanged = 'STATUS_CHANGED';
    case Reopened = 'REOPENED';
}
