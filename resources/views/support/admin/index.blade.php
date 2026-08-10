@extends('layouts.admin')
@section('title', 'Support Center')
@section('heading', 'Support Center')
@section('content')
<section class="hero"><div><p class="eyebrow">Horus Media Support Operations</p><h2>Tickets, ownership, and SLA</h2><p>Work the unassigned queue, prioritize customer impact, and keep public replies separate from confidential Horus notes.</p></div></section>
<article class="workspace-section">
    <form method="get" class="form-grid">
        <label>Search<input class="hm-input" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Ticket number or subject"></label>
        <label>Organization<select class="hm-input" name="organization"><option value="">All organizations</option>@foreach($organizations as $organization)<option value="{{ $organization->id }}" @selected(($filters['organization'] ?? '') === $organization->id)>{{ $organization->name }}</option>@endforeach</select></label>
        <label>Category<select class="hm-input" name="category"><option value="">All categories</option>@foreach($categories as $category)<option value="{{ $category->value }}" @selected(($filters['category'] ?? '') === $category->value)>{{ $category->label() }}</option>@endforeach</select></label>
        <label>Status<select class="hm-input" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ str($status->value)->replace('_', ' ')->title() }}</option>@endforeach</select></label>
        <label>Priority<select class="hm-input" name="priority"><option value="">All priorities</option>@foreach($priorities as $priority)<option value="{{ $priority->value }}" @selected(($filters['priority'] ?? '') === $priority->value)>{{ str($priority->value)->title() }}</option>@endforeach</select></label>
        <label>Assignee<select class="hm-input" name="assignee"><option value="">Anyone</option><option value="UNASSIGNED" @selected(($filters['assignee'] ?? '') === 'UNASSIGNED')>Unassigned</option>@foreach($agents as $agent)<option value="{{ $agent->id }}" @selected(($filters['assignee'] ?? '') === $agent->id)>{{ $agent->name }}</option>@endforeach</select></label>
        <label>SLA<select class="hm-input" name="sla"><option value="">Any SLA state</option><option value="APPROACHING" @selected(($filters['sla'] ?? '') === 'APPROACHING')>Approaching</option><option value="BREACHED" @selected(($filters['sla'] ?? '') === 'BREACHED')>Breached</option></select></label>
        <div class="full status-row"><button class="hm-button-secondary">Apply filters</button><a class="text-link" href="{{ route('admin.support.tickets.index') }}">Reset</a></div>
    </form>
</article>
<div class="table-wrap"><table><thead><tr><th>Ticket</th><th>Organization</th><th>Category</th><th>Status / Priority</th><th>SLA</th><th>Assignee</th><th>Activity</th><th></th></tr></thead><tbody>
@forelse($tickets as $ticket)<tr>
    <td><strong>{{ $ticket->ticket_number }}</strong><span class="table-note">{{ $ticket->subject }}</span></td>
    <td>{{ $ticket->organization->name }}<span class="table-note">{{ $ticket->requester->name }}</span></td>
    <td>{{ $ticket->category->label() }}</td>
    <td><x-status-badge :status="$ticket->status" /> <x-status-badge :status="$ticket->priority" /></td>
    <td><x-status-badge :status="$ticket->firstResponseSlaStatus()" /><span class="table-note">Resolution {{ str($ticket->resolutionSlaStatus()->value)->replace('_', ' ')->lower() }}</span></td>
    <td>{{ $ticket->assignee?->name ?: 'Unassigned' }}</td>
    <td>{{ $ticket->updated_at->diffForHumans() }}<span class="table-note">{{ $ticket->messages_count }} messages · {{ $ticket->attachments_count }} files</span></td>
    <td><a class="text-link" href="{{ route('admin.support.tickets.show', $ticket) }}">Work ticket</a></td>
</tr>@empty<tr><td colspan="8" class="muted">No tickets match these filters.</td></tr>@endforelse
</tbody></table></div><div class="workspace-section">{{ $tickets->links() }}</div>
@endsection
