<?php

namespace App\Services\Support;

use App\Enums\SupportTicketEventType;
use App\Enums\SupportTicketMessageType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketEvent;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SupportTicketService
{
    public function __construct(
        private readonly SupportSlaService $sla,
        private readonly SupportLinkedResourceResolver $resources,
        private readonly SupportAttachmentService $attachments,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(User $actor, array $data, ?UploadedFile $attachment = null): SupportTicket
    {
        $this->requirePermission($actor, 'support.tickets.create');
        $priority = SupportTicketPriority::from($data['priority']);
        if ($priority === SupportTicketPriority::Urgent && ! $actor->isHorusAdministrator()) {
            throw ValidationException::withMessages(['priority' => 'Urgent priority is assigned by Horus Support.']);
        }
        $resource = $this->resources->resolveForOrganization(
            $actor->organization_id,
            $data['linked_resource_type'] ?? null,
            $data['linked_resource_id'] ?? null,
        );

        return DB::transaction(function () use ($actor, $data, $attachment, $priority, $resource): SupportTicket {
            $id = (string) Str::ulid();
            $policy = $this->sla->policyFor($priority);
            $ticket = new SupportTicket([
                'id' => $id,
                'ticket_number' => 'HM-TKT-'.strtoupper(substr($id, -12)),
                'organization_id' => $actor->organization_id,
                'requester_id' => $actor->id,
                'subject' => trim($data['subject']),
                'category' => $data['category'],
                'priority' => $priority,
                'status' => SupportTicketStatus::Open,
                'linked_resource_type' => $data['linked_resource_type'] ?? null,
                'linked_resource_id' => $resource?->getKey(),
                'last_customer_reply_at' => now(),
            ]);
            $ticket->created_at = now();
            $this->sla->initialize($ticket, $policy, $ticket->created_at);
            $ticket->save();

            $message = $ticket->messages()->create([
                'author_id' => $actor->id,
                'type' => SupportTicketMessageType::Public,
                'body' => trim($data['description']),
            ]);
            $this->event($ticket, $actor, SupportTicketEventType::Created, null, $ticket->status->value, [
                'category' => $ticket->category->value,
                'priority' => $ticket->priority->value,
                'linked_resource_type' => $ticket->linked_resource_type?->value,
            ]);
            if ($attachment) {
                $this->attachments->store($attachment, $ticket, $message, $actor);
                $this->event($ticket, $actor, SupportTicketEventType::AttachmentAdded);
            }

            return $ticket->fresh(['slaPolicy', 'requester']);
        }, 3);
    }

    public function reply(User $actor, SupportTicket $ticket, string $body, ?UploadedFile $attachment = null): SupportTicketMessage
    {
        return DB::transaction(function () use ($actor, $ticket, $body, $attachment): SupportTicketMessage {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $isHorus = $actor->isHorusAdministrator();
            if ($isHorus) {
                $this->requirePermission($actor, 'support.admin.reply');
                if ($ticket->status->terminal()) {
                    throw ValidationException::withMessages(['body' => 'Reopen the ticket before adding a public reply.']);
                }
            } else {
                $this->authorizeCustomer($actor, $ticket, 'support.tickets.reply_own');
            }

            $before = $ticket->status;
            if ($isHorus) {
                $ticket->last_horus_reply_at = now();
                $ticket->first_response_at ??= now();
                $ticket->status = SupportTicketStatus::PendingCustomer;
                $this->sla->pause($ticket);
            } else {
                if ($ticket->status->terminal()) {
                    $this->reopenState($ticket);
                    $this->event($ticket, $actor, SupportTicketEventType::Reopened, $before->value, SupportTicketStatus::PendingHorus->value);
                } else {
                    $ticket->status = SupportTicketStatus::PendingHorus;
                }
                $this->sla->resume($ticket);
                $ticket->last_customer_reply_at = now();
            }
            $ticket->save();

            $message = $ticket->messages()->create([
                'author_id' => $actor->id,
                'type' => SupportTicketMessageType::Public,
                'body' => trim($body),
            ]);
            $this->event($ticket, $actor, SupportTicketEventType::PublicReply, $before->value, $ticket->status->value, [
                'audience' => $isHorus ? 'CUSTOMER' : 'HORUS',
            ]);
            if ($attachment) {
                $this->attachments->store($attachment, $ticket, $message, $actor);
                $this->event($ticket, $actor, SupportTicketEventType::AttachmentAdded);
            }

            return $message->fresh(['author', 'attachments']);
        }, 3);
    }

    public function internalNote(User $actor, SupportTicket $ticket, string $body): SupportTicketMessage
    {
        $this->requireHorusPermission($actor, 'support.internal_notes.view');

        return DB::transaction(function () use ($actor, $ticket, $body): SupportTicketMessage {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $message = $ticket->messages()->create([
                'author_id' => $actor->id,
                'type' => SupportTicketMessageType::Internal,
                'body' => trim($body),
            ]);
            $this->event($ticket, $actor, SupportTicketEventType::InternalNote);

            return $message->fresh('author');
        }, 3);
    }

    public function assign(User $actor, SupportTicket $ticket, ?User $assignee): SupportTicket
    {
        $this->requireHorusPermission($actor, 'support.admin.assign');
        if ($assignee && (! $assignee->isHorusAdministrator() || ! $assignee->hasPermission('support.admin.view'))) {
            throw ValidationException::withMessages(['assigned_to' => 'The selected user is not an authorized Horus Support agent.']);
        }

        return DB::transaction(function () use ($actor, $ticket, $assignee): SupportTicket {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $old = $ticket->assigned_to;
            $ticket->assigned_to = $assignee?->id;
            $ticket->save();
            $this->event($ticket, $actor, SupportTicketEventType::Assigned, $old, $assignee?->id);
            $this->audit->record('support.ticket.assigned', $ticket->organization_id, $actor, $ticket,
                ['assigned_to' => $old], ['assigned_to' => $assignee?->id]);

            return $ticket->fresh('assignee');
        }, 3);
    }

    public function reprioritize(User $actor, SupportTicket $ticket, SupportTicketPriority $priority): SupportTicket
    {
        $this->requireHorusPermission($actor, 'support.admin.manage');

        return DB::transaction(function () use ($actor, $ticket, $priority): SupportTicket {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $old = $ticket->priority;
            if ($old === $priority) {
                return $ticket;
            }
            $ticket->priority = $priority;
            $this->sla->reprioritize($ticket, $this->sla->policyFor($priority));
            $ticket->save();
            $this->event($ticket, $actor, SupportTicketEventType::PriorityChanged, $old->value, $priority->value);
            $this->audit->record('support.ticket.priority_changed', $ticket->organization_id, $actor, $ticket,
                ['priority' => $old->value], ['priority' => $priority->value]);

            return $ticket->fresh('slaPolicy');
        }, 3);
    }

    public function transition(User $actor, SupportTicket $ticket, SupportTicketStatus $target): SupportTicket
    {
        $this->requireHorusPermission($actor, 'support.admin.manage');

        return DB::transaction(function () use ($actor, $ticket, $target): SupportTicket {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $ticket->status;
            $allowed = match ($from) {
                SupportTicketStatus::Open, SupportTicketStatus::PendingHorus, SupportTicketStatus::PendingCustomer => [SupportTicketStatus::Resolved],
                SupportTicketStatus::Resolved => [SupportTicketStatus::Closed, SupportTicketStatus::PendingHorus],
                SupportTicketStatus::Closed => [SupportTicketStatus::PendingHorus],
            };
            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages(['status' => "The transition from {$from->value} to {$target->value} is not allowed."]);
            }

            $this->applyStatus($ticket, $target);
            $ticket->save();
            $event = $target === SupportTicketStatus::PendingHorus
                ? SupportTicketEventType::Reopened
                : SupportTicketEventType::StatusChanged;
            $this->event($ticket, $actor, $event, $from->value, $target->value);
            $this->audit->record('support.ticket.status_changed', $ticket->organization_id, $actor, $ticket,
                ['status' => $from->value], ['status' => $target->value]);

            return $ticket;
        }, 3);
    }

    public function customerClose(User $actor, SupportTicket $ticket): SupportTicket
    {
        $this->authorizeCustomer($actor, $ticket, 'support.tickets.reply_own');

        return DB::transaction(function () use ($actor, $ticket): SupportTicket {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status === SupportTicketStatus::Closed) {
                return $ticket;
            }
            $from = $ticket->status;
            $this->applyStatus($ticket, SupportTicketStatus::Closed);
            $ticket->save();
            $this->event($ticket, $actor, SupportTicketEventType::StatusChanged, $from->value, SupportTicketStatus::Closed->value);
            $this->audit->record('support.ticket.status_changed', $ticket->organization_id, $actor, $ticket,
                ['status' => $from->value], ['status' => SupportTicketStatus::Closed->value]);

            return $ticket;
        }, 3);
    }

    public function customerReopen(User $actor, SupportTicket $ticket): SupportTicket
    {
        $this->authorizeCustomer($actor, $ticket, 'support.tickets.reply_own');

        return DB::transaction(function () use ($actor, $ticket): SupportTicket {
            $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (! $ticket->status->terminal()) {
                throw ValidationException::withMessages(['status' => 'Only a resolved or closed ticket can be reopened.']);
            }
            $from = $ticket->status;
            $this->reopenState($ticket);
            $ticket->save();
            $this->event($ticket, $actor, SupportTicketEventType::Reopened, $from->value, SupportTicketStatus::PendingHorus->value);
            $this->audit->record('support.ticket.reopened', $ticket->organization_id, $actor, $ticket,
                ['status' => $from->value], ['status' => SupportTicketStatus::PendingHorus->value]);

            return $ticket;
        }, 3);
    }

    private function applyStatus(SupportTicket $ticket, SupportTicketStatus $status): void
    {
        $this->sla->resume($ticket);
        $ticket->status = $status;
        if ($status === SupportTicketStatus::Resolved) {
            $ticket->resolved_at = now();
            $ticket->closed_at = null;
        } elseif ($status === SupportTicketStatus::Closed) {
            $ticket->closed_at = now();
        } elseif ($status === SupportTicketStatus::PendingHorus) {
            $this->reopenState($ticket);
        }
    }

    private function reopenState(SupportTicket $ticket): void
    {
        $this->sla->resume($ticket);
        $ticket->status = SupportTicketStatus::PendingHorus;
        $ticket->resolved_at = null;
        $ticket->closed_at = null;
        if ($ticket->slaPolicy?->resolution_minutes) {
            $ticket->resolution_due_at = now()->addMinutes($ticket->slaPolicy->resolution_minutes);
        }
    }

    private function event(
        SupportTicket $ticket,
        ?User $actor,
        SupportTicketEventType $event,
        ?string $from = null,
        ?string $to = null,
        array $metadata = [],
    ): SupportTicketEvent {
        return SupportTicketEvent::query()->create([
            'support_ticket_id' => $ticket->id,
            'actor_id' => $actor?->id,
            'event' => $event,
            'from_value' => $from,
            'to_value' => $to,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function authorizeCustomer(User $actor, SupportTicket $ticket, string $permission): void
    {
        $this->requirePermission($actor, $permission);
        if ($actor->isHorusAdministrator() || $actor->organization_id !== $ticket->organization_id) {
            throw new AuthorizationException;
        }
    }

    private function requireHorusPermission(User $actor, string $permission): void
    {
        if (! $actor->isHorusAdministrator()) {
            throw new AuthorizationException;
        }
        $this->requirePermission($actor, $permission);
    }

    private function requirePermission(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException;
        }
    }
}
