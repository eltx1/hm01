@extends('layouts.admin')
@section('title', 'Support Tickets')
@section('heading', 'Support')
@section('content')
<section class="hero"><div><p class="eyebrow">Horus Media Support</p><h2>Your support workspace</h2><p>Create secure organization-aware tickets, follow the public conversation, and see exactly what Horus Support is waiting for.</p></div>@if(auth()->user()->hasPermission('support.tickets.create'))<a class="hm-button-primary button-link" href="{{ route('support.tickets.create') }}">Create ticket</a>@endif</section>

<article class="workspace-section">
    <form method="get" class="form-grid">
        <label>Search<input class="hm-input" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Ticket number or subject"></label>
        <label>Status<select class="hm-input" name="status"><option value="">All statuses</option>@foreach(\App\Enums\SupportTicketStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str($status->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <div class="full status-row"><button class="hm-button-secondary">Filter</button><a class="text-link" href="{{ route('support.tickets.index') }}">Reset</a></div>
    </form>
</article>

<div class="table-wrap">
    <table>
        <thead><tr><th>Ticket</th><th>Category</th><th>Status</th><th>Priority</th><th>SLA</th><th>Last activity</th><th></th></tr></thead>
        <tbody>
        @forelse($tickets as $ticket)
            <tr>
                <td><strong>{{ $ticket->ticket_number }}</strong><span class="table-note">{{ $ticket->subject }}</span></td>
                <td>{{ $ticket->category->label() }}</td>
                <td><x-status-badge :status="$ticket->status" /></td>
                <td><x-status-badge :status="$ticket->priority" /></td>
                <td><x-status-badge :status="$ticket->firstResponseSlaStatus()" /><span class="table-note">First response</span></td>
                <td>{{ $ticket->updated_at->diffForHumans() }}<span class="table-note">{{ $ticket->assignee?->name ?: 'Horus queue' }}</span></td>
                <td><a class="text-link" href="{{ route('support.tickets.show', $ticket) }}">Open</a></td>
            </tr>
        @empty<tr><td colspan="7" class="muted">No support tickets match this view.</td></tr>@endforelse
        </tbody>
    </table>
</div>
<div class="workspace-section">{{ $tickets->links() }}</div>
@endsection
