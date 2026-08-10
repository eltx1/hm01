<?php

namespace App\Http\Controllers\Support;

use App\Enums\SupportLinkedResourceType;
use App\Enums\SupportTicketCategory;
use App\Enums\SupportTicketPriority;
use App\Enums\SupportTicketStatus;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Support\SupportLinkedResourceResolver;
use App\Services\Support\SupportTicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerSupportTicketController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeCustomer($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(SupportTicketStatus::class)],
        ]);
        $tickets = SupportTicket::query()
            ->with(['requester:id,name', 'assignee:id,name', 'slaPolicy'])
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(fn ($match) => $match
                    ->where('ticket_number', 'like', '%'.$search.'%')
                    ->orWhere('subject', 'like', '%'.$search.'%'));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('updated_at')->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('support.customer.index', compact('tickets', 'filters'));
    }

    public function create(Request $request, SupportLinkedResourceResolver $resources): View
    {
        $this->authorizeCustomer($request, 'support.tickets.create');

        return view('support.customer.create', [
            'categories' => SupportTicketCategory::cases(),
            'priorities' => [SupportTicketPriority::Low, SupportTicketPriority::Normal, SupportTicketPriority::High],
            'resources' => $resources->optionsForOrganization($request->user()->organization_id),
        ]);
    }

    public function store(Request $request, SupportTicketService $tickets): RedirectResponse
    {
        $this->authorizeCustomer($request, 'support.tickets.create');
        if ($request->filled('linked_resource')) {
            [$type, $id] = array_pad(explode('|', (string) $request->input('linked_resource'), 2), 2, null);
            $request->merge(['linked_resource_type' => $type, 'linked_resource_id' => $id]);
        }
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:4', 'max:255'],
            'description' => ['required', 'string', 'min:4', 'max:10000'],
            'category' => ['required', Rule::enum(SupportTicketCategory::class)],
            'priority' => ['required', Rule::in([SupportTicketPriority::Low->value, SupportTicketPriority::Normal->value, SupportTicketPriority::High->value])],
            'linked_resource' => ['nullable', 'string', 'max:100'],
            'linked_resource_type' => ['nullable', Rule::enum(SupportLinkedResourceType::class), 'required_with:linked_resource_id'],
            'linked_resource_id' => ['nullable', 'string', 'max:40', 'required_with:linked_resource_type'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);
        $ticket = $tickets->create($request->user(), $data, $request->file('attachment'));

        return redirect()->route('support.tickets.show', $ticket)->with('status', 'Support ticket created.');
    }

    public function show(Request $request, SupportTicket $ticket, SupportLinkedResourceResolver $resources): View
    {
        $this->authorizeTicket($request, $ticket);
        $ticket->load([
            'requester:id,name', 'assignee:id,name', 'slaPolicy',
            'publicMessages' => fn ($query) => $query->with(['author:id,name,organization_id', 'attachments']),
        ]);

        return view('support.customer.show', [
            'ticket' => $ticket,
            'linkedResourceLabel' => $resources->label($ticket->linked_resource_type, $ticket->linked_resource_id, $ticket->organization_id),
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket, 'support.tickets.reply_own');
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);
        $tickets->reply($request->user(), $ticket, $data['body'], $request->file('attachment'));

        return back()->with('status', 'Reply added.');
    }

    public function close(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket, 'support.tickets.reply_own');
        $tickets->customerClose($request->user(), $ticket);

        return back()->with('status', 'Ticket closed.');
    }

    public function reopen(Request $request, SupportTicket $ticket, SupportTicketService $tickets): RedirectResponse
    {
        $this->authorizeTicket($request, $ticket, 'support.tickets.reply_own');
        $tickets->customerReopen($request->user(), $ticket);

        return back()->with('status', 'Ticket reopened for Horus Support.');
    }

    private function authorizeCustomer(Request $request, string $permission = 'support.tickets.view_own'): void
    {
        $user = $request->user();
        if ($user->isHorusAdministrator() || ! $user->hasPermission($permission)) {
            throw new AuthorizationException;
        }
    }

    private function authorizeTicket(Request $request, SupportTicket $ticket, string $permission = 'support.tickets.view_own'): void
    {
        $this->authorizeCustomer($request, $permission);
        if ($ticket->organization_id !== $request->user()->organization_id) {
            throw new AuthorizationException;
        }
    }
}
