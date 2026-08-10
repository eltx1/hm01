<?php

namespace App\Http\Controllers\Support;

use App\Enums\OrganizationType;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketMessageType;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportLinkedResourceResolver;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'organization' => ['nullable', 'string', 'max:40'],
            'category' => ['nullable', Rule::enum(SupportTicketCategory::class)],
            'status' => ['nullable', Rule::enum(SupportTicketStatus::class)],
            'priority' => ['nullable', Rule::enum(SupportTicketPriority::class)],
            'assignee' => ['nullable', 'string', 'max:40'],
            'sla' => ['nullable', Rule::in(['APPROACHING', 'BREACHED'])],
        ]);
        $tickets = SupportTicket::withoutGlobalScopes()
            ->with(['organization:id,name', 'requester:id,name', 'assignee:id,name', 'slaPolicy'])
            ->withCount(['messages', 'attachments'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(fn ($match) => $match
                    ->where('ticket_number', 'like', '%'.$search.'%')
                    ->orWhere('subject', 'like', '%'.$search.'%'));
            })
            ->when($filters['organization'] ?? null, fn ($query, string $id) => $query->where('organization_id', $id))
            ->when($filters['category'] ?? null, fn ($query, string $value) => $query->where('category', $value))
            ->when($filters['status'] ?? null, fn ($query, string $value) => $query->where('status', $value))
            ->when($filters['priority'] ?? null, fn ($query, string $value) => $query->where('priority', $value))
            ->when($filters['assignee'] ?? null, fn ($query) => $filters['assignee'] === 'UNASSIGNED'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', $filters['assignee']))
            ->when(($filters['sla'] ?? null) === 'BREACHED', fn ($query) => $query->where(function ($sla): void {
                $sla->where(fn ($first) => $first->whereNull('first_response_at')->where('first_response_due_at', '<', now()))
                    ->orWhere(fn ($resolution) => $resolution->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])
                        ->where('resolution_due_at', '<', now()));
            }))
            ->when(($filters['sla'] ?? null) === 'APPROACHING', fn ($query) => $query->where(function ($sla): void {
                $sla->where(fn ($first) => $first->whereNull('first_response_at')->whereBetween('first_response_due_at', [now(), now()->addHours(2)]))
                    ->orWhere(fn ($resolution) => $resolution->whereNotIn('status', [SupportTicketStatus::Resolved->value, SupportTicketStatus::Closed->value])
                        ->whereBetween('resolution_due_at', [now(), now()->addHours(2)]));
            }))
            ->latest('updated_at')->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('support.admin.index', [
            'tickets' => $tickets,
            'filters' => $filters,
            'organizations' => Organization::query()->whereHas('supportTickets')->orderBy('name')->get(['id', 'name']),
            'agents' => $this->agents(),
            'categories' => SupportTicketCategory::cases(),
            'priorities' => SupportTicketPriority::cases(),
            'statuses' => SupportTicketStatus::cases(),
        ]);
    }

    public function show(Request $request, SupportTicket $ticket, SupportLinkedResourceResolver $resources): View
    {
        $messageQuery = fn ($query) => $query
            ->when(! $request->user()->hasPermission('support.internal_notes.view'), fn ($messages) => $messages->where('type', SupportTicketMessageType::Public->value))
            ->with(['author:id,name,organization_id', 'attachments']);
        $ticket = SupportTicket::withoutGlobalScopes()->whereKey($ticket->id)->firstOrFail();
        $ticket->load([
            'organization:id,name', 'requester:id,name', 'assignee:id,name', 'slaPolicy',
            'messages' => $messageQuery, 'events.actor:id,name',
        ]);

        return view('support.admin.show', [
            'ticket' => $ticket,
            'agents' => $this->agents(),
            'priorities' => SupportTicketPriority::cases(),
            'linkedResourceLabel' => $resources->label($ticket->linked_resource_type, $ticket->linked_resource_id, $ticket->organization_id),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);
        $tickets->reply($request->user(), $ticket, $data['body'], $request->file('attachment'));

        return back()->with('status', 'Public reply sent.');
    }

    public function note(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:10000']]);
        $tickets->internalNote($request->user(), $ticket, $data['body']);

        return back()->with('status', 'Internal note added.');
    }

    public function assign(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['assigned_to' => ['nullable', 'string', 'exists:users,id']]);
        $assignee = isset($data['assigned_to']) ? User::query()->findOrFail($data['assigned_to']) : null;
        $tickets->assign($request->user(), $ticket, $assignee);

        return back()->with('status', 'Assignment updated.');
    }

    public function priority(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['priority' => ['required', Rule::enum(SupportTicketPriority::class)]]);
        $tickets->reprioritize($request->user(), $ticket, SupportTicketPriority::from($data['priority']));

        return back()->with('status', 'Priority updated.');
    }

    public function status(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(SupportTicketStatus::class)]]);
        $tickets->transition($request->user(), $ticket, SupportTicketStatus::from($data['status']));

        return back()->with('status', 'Ticket status updated.');
    }

    private function agents()
    {
        return User::query()
            ->whereHas('organization', fn ($query) => $query->where('type', OrganizationType::HorusMedia->value))
            ->whereHas('roles.permissions', fn ($query) => $query->where('name', 'support.admin.view'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
