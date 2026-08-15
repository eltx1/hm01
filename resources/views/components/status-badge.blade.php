@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $normalized = strtoupper($value);
    $tone = match ($normalized) {
        'ACTIVE', 'APPROVED', 'ACCEPTED', 'HEALTHY', 'VERIFIED', 'SUCCEEDED', 'DEPLOYED', 'PAID', 'FINALIZED', 'SIGNED', 'COMPLIANT', 'FRESH', 'READY', 'ELIGIBLE', 'MATCHED', 'RESOLVED', 'MET', 'SUCCESS' => 'success',
        'FAILED', 'REJECTED', 'WITHDRAWN', 'SUSPENDED', 'CLOSED', 'ARCHIVED', 'EXPIRED', 'TERMINATED', 'CANCELLED', 'MISSING', 'INVALID', 'CONFLICT', 'UNREACHABLE', 'BREACHED', 'URGENT', 'CRITICAL' => 'danger',
        'PENDING', 'SUBMITTED', 'UNDER_REVIEW', 'MORE_INFO_REQUIRED', 'EMAIL_VERIFICATION_REQUIRED', 'PENDING_REVIEW', 'PENDING_VERIFICATION', 'PENDING_HORUS', 'PENDING_CUSTOMER', 'REVIEW_REQUIRED', 'NEEDS_UPDATE', 'INCOMPLETE', 'REQUIRED', 'RECEIVED', 'SCHEDULED', 'PROCESSING', 'PARTIALLY_PAID', 'HELD', 'RETRY_SCHEDULED', 'SENT', 'DEGRADED', 'PARTIAL', 'STALE', 'DUE', 'BLOCKED', 'APPROACHING', 'HIGH', 'WARNING', 'PAUSED' => 'warning',
        default => 'neutral',
    };
    $label = match ($normalized) {
        'UNDER_REVIEW', 'PENDING_REVIEW' => 'Pending review',
        'MORE_INFO_REQUIRED', 'EMAIL_VERIFICATION_REQUIRED', 'NEEDS_UPDATE', 'INCOMPLETE', 'REQUIRED' => 'Action required',
        'NOT_CONFIGURED' => 'Not configured',
        'NOT_APPLICABLE', 'N/A', 'NA' => 'Not applicable',
        'PARTIALLY_PAID' => 'Partially paid',
        'RETRY_SCHEDULED' => 'Retry scheduled',
        default => str($value)->replace('_', ' ')->headline()->toString(),
    };
@endphp

<span {{ $attributes->class(['status-badge', 'status-badge-'.$tone]) }}>{{ $label }}</span>
