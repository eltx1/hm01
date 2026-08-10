<?php

namespace App\Models;

use App\Enums\SupportLinkedResourceType;
use App\Enums\SupportSlaStatus;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketMessageType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $fillable = [
        'id', 'ticket_number', 'organization_id', 'requester_id', 'assigned_to',
        'sla_policy_id', 'subject', 'category', 'priority', 'status',
        'linked_resource_type', 'linked_resource_id', 'last_customer_reply_at',
        'last_horus_reply_at', 'first_response_at', 'resolved_at', 'closed_at',
        'first_response_due_at', 'resolution_due_at', 'sla_paused_at', 'sla_paused_seconds',
    ];

    protected function casts(): array
    {
        return [
            'category' => SupportTicketCategory::class,
            'priority' => SupportTicketPriority::class,
            'status' => SupportTicketStatus::class,
            'linked_resource_type' => SupportLinkedResourceType::class,
            'last_customer_reply_at' => 'datetime',
            'last_horus_reply_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'sla_paused_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SupportSlaPolicy::class, 'sla_policy_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->oldest();
    }

    public function publicMessages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)
            ->where('type', SupportTicketMessageType::Public->value)
            ->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class)->latest('created_at');
    }

    public function firstResponseSlaStatus(): SupportSlaStatus
    {
        if (! $this->first_response_due_at) {
            return SupportSlaStatus::NotApplicable;
        }
        if ($this->first_response_at) {
            return $this->first_response_at->lte($this->first_response_due_at)
                ? SupportSlaStatus::Met
                : SupportSlaStatus::Breached;
        }
        if ($this->sla_paused_at) {
            return SupportSlaStatus::Paused;
        }
        if (now()->gt($this->first_response_due_at)) {
            return SupportSlaStatus::Breached;
        }

        $warningMinutes = (int) ($this->slaPolicy?->warning_before_minutes ?? 30);

        return now()->addMinutes($warningMinutes)->gte($this->first_response_due_at)
            ? SupportSlaStatus::Approaching
            : SupportSlaStatus::OnTrack;
    }

    public function resolutionSlaStatus(): SupportSlaStatus
    {
        if (! $this->resolution_due_at) {
            return SupportSlaStatus::NotApplicable;
        }
        $completedAt = $this->resolved_at ?? $this->closed_at;
        if ($completedAt) {
            return $completedAt->lte($this->resolution_due_at)
                ? SupportSlaStatus::Met
                : SupportSlaStatus::Breached;
        }
        if ($this->sla_paused_at) {
            return SupportSlaStatus::Paused;
        }
        if (now()->gt($this->resolution_due_at)) {
            return SupportSlaStatus::Breached;
        }

        $warningMinutes = (int) ($this->slaPolicy?->warning_before_minutes ?? 30);

        return now()->addMinutes($warningMinutes)->gte($this->resolution_due_at)
            ? SupportSlaStatus::Approaching
            : SupportSlaStatus::OnTrack;
    }
}
