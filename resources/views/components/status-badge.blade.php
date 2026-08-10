@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match (strtoupper($value)) {
        'ACTIVE', 'APPROVED', 'ACCEPTED', 'HEALTHY', 'VERIFIED', 'SUCCEEDED', 'DEPLOYED', 'PAID', 'FINALIZED', 'SIGNED', 'COMPLIANT', 'FRESH' => 'success',
        'FAILED', 'REJECTED', 'SUSPENDED', 'CLOSED', 'ARCHIVED', 'EXPIRED', 'TERMINATED', 'CANCELLED', 'MISSING', 'INVALID', 'CONFLICT', 'UNREACHABLE' => 'danger',
        'PENDING', 'PENDING_REVIEW', 'PENDING_VERIFICATION', 'REVIEW_REQUIRED', 'NEEDS_UPDATE', 'INCOMPLETE', 'REQUIRED', 'RECEIVED', 'PROCESSING', 'PARTIALLY_PAID', 'RETRY_SCHEDULED', 'SENT', 'DEGRADED', 'PARTIAL', 'STALE', 'DUE' => 'warning',
        default => 'neutral',
    };
@endphp

<span {{ $attributes->class(['status-badge', 'status-badge-'.$tone]) }}>{{ str($value)->replace('_', ' ')->headline() }}</span>
