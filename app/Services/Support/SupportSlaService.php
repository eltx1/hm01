<?php

namespace App\Services\Support;

use App\Enums\SupportTicketPriority;
use App\Models\SupportSlaPolicy;
use App\Models\SupportTicket;
use Illuminate\Support\Carbon;

final class SupportSlaService
{
    public function policyFor(SupportTicketPriority $priority): SupportSlaPolicy
    {
        $settings = config('support.sla.'.$priority->value);

        return SupportSlaPolicy::query()->firstOrCreate(
            ['priority' => $priority->value],
            $settings + ['pause_while_waiting_on_customer' => true, 'is_active' => true],
        );
    }

    public function initialize(SupportTicket $ticket, SupportSlaPolicy $policy, Carbon $startedAt): void
    {
        $ticket->sla_policy_id = $policy->id;
        $ticket->first_response_due_at = $startedAt->copy()->addMinutes($policy->first_response_minutes);
        $ticket->resolution_due_at = $policy->resolution_minutes
            ? $startedAt->copy()->addMinutes($policy->resolution_minutes)
            : null;
    }

    public function reprioritize(SupportTicket $ticket, SupportSlaPolicy $policy): void
    {
        $ticket->sla_policy_id = $policy->id;
        $pausedSeconds = (int) $ticket->sla_paused_seconds;
        $ticket->first_response_due_at = $ticket->created_at->copy()
            ->addMinutes($policy->first_response_minutes)->addSeconds($pausedSeconds);
        $ticket->resolution_due_at = $policy->resolution_minutes
            ? $ticket->created_at->copy()->addMinutes($policy->resolution_minutes)->addSeconds($pausedSeconds)
            : null;
    }

    public function pause(SupportTicket $ticket): void
    {
        if ($ticket->slaPolicy?->pause_while_waiting_on_customer && ! $ticket->sla_paused_at) {
            $ticket->sla_paused_at = now();
        }
    }

    public function resume(SupportTicket $ticket): void
    {
        if (! $ticket->sla_paused_at) {
            return;
        }

        $seconds = $ticket->sla_paused_at->diffInSeconds(now());
        $ticket->sla_paused_seconds = (int) $ticket->sla_paused_seconds + $seconds;
        if (! $ticket->first_response_at && $ticket->first_response_due_at) {
            $ticket->first_response_due_at = $ticket->first_response_due_at->addSeconds($seconds);
        }
        if ($ticket->resolution_due_at) {
            $ticket->resolution_due_at = $ticket->resolution_due_at->addSeconds($seconds);
        }
        $ticket->sla_paused_at = null;
    }
}
