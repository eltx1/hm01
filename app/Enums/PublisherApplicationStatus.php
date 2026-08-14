<?php

namespace App\Enums;

enum PublisherApplicationStatus: string
{
    case EmailVerificationRequired = 'EMAIL_VERIFICATION_REQUIRED';
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case UnderReview = 'UNDER_REVIEW';
    case MoreInfoRequired = 'MORE_INFO_REQUIRED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::EmailVerificationRequired => [self::Draft, self::Withdrawn],
            self::Draft => [self::Submitted, self::Withdrawn],
            self::Submitted => [self::UnderReview, self::Withdrawn],
            self::UnderReview => [self::MoreInfoRequired, self::Approved, self::Rejected, self::Withdrawn],
            self::MoreInfoRequired => [self::Submitted, self::Withdrawn],
            self::Approved, self::Rejected, self::Withdrawn => [],
        }, true);
    }

    public function applicantMayEdit(): bool
    {
        return in_array($this, [self::Draft, self::MoreInfoRequired], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Withdrawn], true);
    }
}
