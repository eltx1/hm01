@props(['status'])

@php
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $tone = match (strtoupper($value)) {
        'ACTIVE', 'APPROVED', 'HEALTHY', 'VERIFIED', 'SUCCEEDED', 'DEPLOYED', 'PAID', 'FINALIZED', 'SIGNED' => 'success',
        'FAILED', 'REJECTED', 'SUSPENDED', 'CLOSED', 'ARCHIVED', 'EXPIRED', 'TERMINATED', 'CANCELLED' => 'danger',
        'PENDING', 'PENDING_REVIEW', 'PENDING_VERIFICATION', 'REVIEW_REQUIRED', 'PROCESSING', 'RETRY_SCHEDULED', 'SENT', 'DEGRADED' => 'warning',
        default => 'neutral',
    };
@endphp

<span {{ $attributes->class(['status-badge', 'status-badge-'.$tone]) }}>{{ str($value)->replace('_', ' ')->headline() }}</span>
