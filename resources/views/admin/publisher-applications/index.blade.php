@extends('layouts.admin')
@section('title', 'Publisher Applications')
@section('heading', 'Publisher Applications')
@section('content')
<section class="metric-grid" aria-label="Application counts">
    @foreach([\App\Enums\PublisherApplicationStatus::Submitted, \App\Enums\PublisherApplicationStatus::UnderReview, \App\Enums\PublisherApplicationStatus::MoreInfoRequired, \App\Enums\PublisherApplicationStatus::Approved, \App\Enums\PublisherApplicationStatus::Rejected] as $state)
    <article><p class="eyebrow">{{ str($state->value)->replace('_', ' ')->headline() }}</p><strong class="metric">{{ $counts[$state->value] ?? 0 }}</strong></article>
    @endforeach
</section>
<article class="workspace-section">
    <form method="GET" class="form-grid">
        <label>Status<select name="status"><option value="">All</option><option value="NEW" @selected($status === 'NEW')>New</option>@foreach(\App\Enums\PublisherApplicationStatus::cases() as $state)<option value="{{ $state->value }}" @selected($status === $state->value)>{{ str($state->value)->replace('_', ' ')->headline() }}</option>@endforeach</select></label>
        <label>At least this many days old<input type="number" name="age" min="0" max="3650" value="{{ $age ?: '' }}"></label>
        <label>Domain<input name="domain" value="{{ $domain }}"></label>
        <label>Applicant name or email<input name="applicant" value="{{ $applicant }}"></label>
        <button>Apply filters</button><a class="button-link" href="{{ route('admin.publisher-applications.index') }}">Clear</a>
    </form>
</article>
<div class="table-wrap"><table><thead><tr><th>Applicant</th><th>Publisher / domain</th><th>Status</th><th>Submitted</th><th>Age</th><th>Action</th></tr></thead><tbody>
@forelse($applications as $application)<tr><td>{{ $application->applicant->name }}<small class="table-note">{{ $application->applicant->email }}</small></td><td>{{ $application->publisher->display_name }}<small class="table-note">{{ $application->primary_domain }}</small></td><td><x-status-badge :status="$application->status" /></td><td>{{ $application->last_submitted_at ?: 'Draft only' }}</td><td>{{ $application->created_at->diffForHumans() }}</td><td><a href="{{ route('admin.publisher-applications.show', $application) }}">Review</a></td></tr>@empty<tr><td colspan="6">No applications match these filters.</td></tr>@endforelse
</tbody></table></div>{{ $applications->links() }}
@endsection
