@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match (strtoupper($value)) {
        'ACTIVE', 'APPROVED', 'ACCEPTED', 'HEALTHY', 'VERIFIED', 'SUCCEEDED', 'DEPLOYED', 'PAID', 'FINALIZED', 'SIGNED', 'COMPLIANT', 'FRESH', 'READY', 'ELIGIBLE', 'MATCHED', 'RESOLVED' => 'success',
        'FAILED', 'REJECTED', 'SUSPENDED', 'CLOSED', 'ARCHIVED', 'EXPIRED', 'TERMINATED', 'CANCELLED', 'MISSING', 'INVALID', 'CONFLICT', 'UNREACHABLE' => 'danger',
        'PENDING', 'PENDING_REVIEW', 'PENDING_VERIFICATION', 'REVIEW_REQUIRED', 'NEEDS_UPDATE', 'INCOMPLETE', 'REQUIRED', 'RECEIVED', 'SCHEDULED', 'PROCESSING', 'PARTIALLY_PAID', 'HELD', 'RETRY_SCHEDULED', 'SENT', 'DEGRADED', 'PARTIAL', 'STALE', 'DUE', 'BLOCKED' => 'warning',
        default => 'neutral',
    };
@endphp

<span {{ $attributes->class(['status-badge', 'status-badge-'.$tone]) }}>{{ str($value)->replace('_', ' ')->headline() }}</span>
