<?php

namespace App\Services\Support;

use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Enums\SupportSlaStatus;
use App\Enums\SupportTicketEventType;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Services\Notifications\HorusNotificationService;

final class SupportSlaMonitor
{
    public function __construct(private readonly HorusNotificationService $notifications) {}

    public function run(int $limit = 500): int
    {
        $processed = 0;
        SupportTicket::withoutGlobalScopes()->with('slaPolicy')
            ->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])
            ->whereNull('sla_paused_at')
            ->where(function ($query): void {
                $query->where(fn ($first) => $first->whereNull('first_response_at')->whereNotNull('first_response_due_at'))
                    ->orWhereNotNull('resolution_due_at');
            })
            ->orderByRaw('COALESCE(first_response_due_at, resolution_due_at)')
            ->limit(max(1, min(2000, $limit)))
            ->get()
            ->each(function (SupportTicket $ticket) use (&$processed): void {
                if (! $ticket->first_response_at) {
                    $processed += $this->emit($ticket, 'FIRST_RESPONSE', $ticket->firstResponseSlaStatus(), $ticket->first_response_due_at?->toIso8601String());
                }
                $processed += $this->emit($ticket, 'RESOLUTION', $ticket->resolutionSlaStatus(), $ticket->resolution_due_at?->toIso8601String());
            });

        return $processed;
    }

    private function emit(SupportTicket $ticket, string $metric, SupportSlaStatus $status, ?string $dueKey): int
    {
        if (! in_array($status, [SupportSlaStatus::Approaching, SupportSlaStatus::Breached], true) || ! $dueKey) {
            return 0;
        }
        $created = $this->notifications->notify($this->notifications->horusRecipients('support.admin.view'), [
            'category' => NotificationCategory::Support,
            'type' => 'SUPPORT_SLA_'.$status->value,
            'severity' => $status === SupportSlaStatus::Breached ? NotificationSeverity::Critical : NotificationSeverity::Warning,
            'title' => $ticket->ticket_number.' SLA '.str($status->value)->lower()->value(),
            'message' => str($metric)->lower()->replace('_', ' ')->headline()->value().' deadline '.($status === SupportSlaStatus::Breached ? 'was breached' : 'is approaching').'.',
            'event_key' => 'support:sla:'.$ticket->id.':'.$metric.':'.$status->value.':'.$dueKey,
            'related_type' => 'SUPPORT_TICKET', 'related_id' => $ticket->id,
            'action_route' => 'admin.support.tickets.show', 'action_parameters' => ['ticket' => $ticket->id],
        ]);
        if ($created > 0) {
            $ticket->events()->create([
                'event' => $status === SupportSlaStatus::Breached ? SupportTicketEventType::SlaBreached : SupportTicketEventType::SlaWarning,
                'to_value' => $status->value,
                'metadata' => ['metric' => $metric, 'due_at' => $dueKey],
            ]);
        }

        return $created;
    }
}
