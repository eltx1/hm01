@extends('layouts.admin')
@section('title', $ticket->ticket_number)
@section('heading', $ticket->ticket_number)
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $ticket->category->label() }}</p><h2>{{ $ticket->subject }}</h2><p>{{ $linkedResourceLabel ?: 'No resource linked' }} · Created by {{ $ticket->requester->name }} on {{ $ticket->created_at->toDayDateTimeString() }}</p></div><div class="status-row"><x-status-badge :status="$ticket->status" /><x-status-badge :status="$ticket->priority" /></div></section>

<section class="metric-grid workspace-section">
    <article><p class="eyebrow">First response SLA</p><x-status-badge :status="$ticket->firstResponseSlaStatus()" /><span class="table-note">{{ $ticket->first_response_at?->toDayDateTimeString() ?: ($ticket->first_response_due_at?->toDayDateTimeString() ?: 'Not applicable') }}</span></article>
    <article><p class="eyebrow">Resolution SLA</p><x-status-badge :status="$ticket->resolutionSlaStatus()" /><span class="table-note">{{ $ticket->resolution_due_at?->toDayDateTimeString() ?: 'Not configured' }}</span></article>
    <article><p class="eyebrow">Assigned agent</p><strong>{{ $ticket->assignee?->name ?: 'Horus Support queue' }}</strong></article>
    <article><p class="eyebrow">Waiting on</p><strong>{{ $ticket->status === \App\Enums\SupportTicketStatus::PendingCustomer ? 'Your organization' : ($ticket->status->terminal() ? 'No action' : 'Horus Support') }}</strong></article>
</section>

<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Public conversation</p><h2>Ticket thread</h2></div></div>
    <div class="support-thread">
    @foreach($ticket->publicMessages as $message)
        <section class="support-message {{ $message->author?->organization_id === $ticket->organization_id ? 'support-message-customer' : 'support-message-horus' }}">
            <header><strong>{{ $message->author?->name ?: 'Former user' }}</strong><span>{{ $message->created_at->toDayDateTimeString() }}</span></header>
            <p>{!! nl2br(e($message->body)) !!}</p>
            @foreach($message->attachments as $attachment)<a class="text-link" href="{{ route('support.attachments.download', [$ticket, $attachment]) }}">Download {{ $attachment->original_name }} · {{ number_format($attachment->size_bytes / 1024, 1) }} KB</a>@endforeach
        </section>
    @endforeach
    </div>
</article>

@if(!$ticket->status->terminal() && auth()->user()->hasPermission('support.tickets.reply_own'))
<article class="workspace-section"><h2>Add a reply</h2><form method="post" action="{{ route('support.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="form-stack">@csrf<label>Message<textarea class="hm-input" name="body" rows="7" maxlength="10000" required></textarea></label><label>Attachment<input class="hm-input" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv"></label><button class="hm-button-primary">Send reply</button></form></article>
@endif

@if(auth()->user()->hasPermission('support.tickets.reply_own'))
<article class="workspace-section"><div class="status-row">@if($ticket->status->terminal())<form method="post" action="{{ route('support.tickets.reopen', $ticket) }}">@csrf @method('PATCH')<button class="hm-button-secondary">Reopen ticket</button></form>@else<form method="post" action="{{ route('support.tickets.close', $ticket) }}">@csrf @method('PATCH')<button class="hm-button-secondary">Close ticket</button></form>@endif<a class="text-link" href="{{ route('support.tickets.index') }}">Back to tickets</a></div></article>
@endif
@endsection
