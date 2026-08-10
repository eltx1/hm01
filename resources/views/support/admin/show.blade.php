@extends('layouts.admin')
@section('title', $ticket->ticket_number)
@section('heading', $ticket->ticket_number)
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $ticket->organization->name }} · {{ $ticket->category->label() }}</p><h2>{{ $ticket->subject }}</h2><p>{{ $linkedResourceLabel ?: 'No linked resource' }} · Requested by {{ $ticket->requester->name }} · {{ $ticket->created_at->toDayDateTimeString() }}</p></div><div class="status-row"><x-status-badge :status="$ticket->status" /><x-status-badge :status="$ticket->priority" /></div></section>

<section class="metric-grid workspace-section">
    <article><p class="eyebrow">First response</p><x-status-badge :status="$ticket->firstResponseSlaStatus()" /><span class="table-note">Due {{ $ticket->first_response_due_at?->toDayDateTimeString() ?: '—' }}</span></article>
    <article><p class="eyebrow">Resolution</p><x-status-badge :status="$ticket->resolutionSlaStatus()" /><span class="table-note">Due {{ $ticket->resolution_due_at?->toDayDateTimeString() ?: '—' }}</span></article>
    <article><p class="eyebrow">Last customer reply</p><strong>{{ $ticket->last_customer_reply_at?->diffForHumans() ?: '—' }}</strong></article>
    <article><p class="eyebrow">Last Horus reply</p><strong>{{ $ticket->last_horus_reply_at?->diffForHumans() ?: '—' }}</strong></article>
</section>

<section class="split-grid">
<article><h2>Ownership</h2>
    @if(auth()->user()->hasPermission('support.admin.assign'))
        <form method="post" action="{{ route('admin.support.tickets.assign', $ticket) }}" class="form-stack">
            @csrf
            @method('PATCH')
            <label>Assigned agent<select class="hm-input" name="assigned_to"><option value="">Unassigned queue</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected($ticket->assigned_to === $agent->id)>{{ $agent->name }}</option>@endforeach</select></label>
            <button class="hm-button-secondary">Update assignment</button>
        </form>
    @else
        <p>{{ $ticket->assignee?->name ?: 'Unassigned queue' }}</p>
    @endif
</article>
<article><h2>Operational state</h2>
    @if(auth()->user()->hasPermission('support.admin.manage'))
    <form method="post" action="{{ route('admin.support.tickets.priority', $ticket) }}" class="inline-form">@csrf @method('PATCH')<select class="hm-input" name="priority">@foreach($priorities as $priority)<option value="{{ $priority->value }}" @selected($ticket->priority === $priority)>{{ str($priority->value)->title() }}</option>@endforeach</select><button class="hm-button-secondary">Set priority</button></form>
    <div class="status-row workspace-section">
        @if(!$ticket->status->terminal())
            <form method="post" action="{{ route('admin.support.tickets.status', $ticket) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="RESOLVED"><button class="hm-button-primary">Resolve</button></form>
        @elseif($ticket->status === \App\Enums\SupportTicketStatus::Resolved)
            <form method="post" action="{{ route('admin.support.tickets.status', $ticket) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="CLOSED"><button class="hm-button-secondary">Close</button></form>
            <form method="post" action="{{ route('admin.support.tickets.status', $ticket) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="PENDING_HORUS"><button class="hm-button-secondary">Reopen</button></form>
        @else
            <form method="post" action="{{ route('admin.support.tickets.status', $ticket) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="PENDING_HORUS"><button class="hm-button-secondary">Reopen</button></form>
        @endif
    </div>
    @endif
</article>
</section>

<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Public and internal history</p><h2>Ticket thread</h2></div></div><div class="support-thread">
@foreach($ticket->messages as $message)
    <section class="support-message {{ $message->type === \App\Enums\SupportTicketMessageType::Internal ? 'support-message-internal' : ($message->author?->organization_id === $ticket->organization_id ? 'support-message-customer' : 'support-message-horus') }}">
        <header><strong>{{ $message->author?->name ?: 'Former user' }}
            @if($message->type === \App\Enums\SupportTicketMessageType::Internal)
                · Internal Horus note
            @endif
        </strong><span>{{ $message->created_at->toDayDateTimeString() }}</span></header>
        <p>{!! nl2br(e($message->body)) !!}</p>
        @foreach($message->attachments as $attachment)<a class="text-link" href="{{ route('support.attachments.download', [$ticket, $attachment]) }}">Download {{ $attachment->original_name }} · {{ number_format($attachment->size_bytes / 1024, 1) }} KB</a>@endforeach
    </section>
@endforeach</div></article>

@if(!$ticket->status->terminal() && auth()->user()->hasPermission('support.admin.reply'))
<section class="split-grid">
    <article><h2>Public reply</h2><p class="muted">Visible to the customer. Sending it moves the ticket to Pending Customer.</p><form method="post" action="{{ route('admin.support.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="form-stack">@csrf<label>Message<textarea class="hm-input" name="body" rows="7" maxlength="10000" required></textarea></label><label>Attachment<input class="hm-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv"></label><button class="hm-button-primary">Send public reply</button></form></article>
    @if(auth()->user()->hasPermission('support.internal_notes.view'))
        <article class="support-note-panel"><h2>Internal note</h2><p>Visible only to authorized Horus staff. It is excluded from every customer page and notification.</p><form method="post" action="{{ route('admin.support.tickets.note', $ticket) }}" class="form-stack">@csrf<label>Internal note<textarea class="hm-input" name="body" rows="7" maxlength="10000" required></textarea></label><button class="hm-button-secondary">Add internal note</button></form></article>
    @endif
</section>
@endif

<article class="workspace-section"><div class="workspace-heading"><div><p class="eyebrow">Immutable ticket history</p><h2>Events</h2></div></div>@foreach($ticket->events as $event)<div class="event"><div><strong>{{ str($event->event->value)->replace('_', ' ')->title() }}</strong><br><span>{{ $event->actor?->name ?: 'System' }} · {{ $event->created_at->toDayDateTimeString() }}</span></div><span>{{ $event->from_value }} @if($event->to_value)→ {{ $event->to_value }}@endif</span></div>@endforeach</article>
@endsection
