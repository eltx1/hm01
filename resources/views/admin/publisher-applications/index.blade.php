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
    <div class="workspace-heading"><div><p class="eyebrow">Review queue</p><h2>Find applications</h2><p class="muted">Filter the queue without changing application state.</p></div></div>
    <form method="GET" class="form-grid">
        <label for="application-status">Status<select id="application-status" class="hm-input" name="status"><option value="">All</option><option value="NEW" @selected($status === 'NEW')>New</option>@foreach(\App\Enums\PublisherApplicationStatus::cases() as $state)<option value="{{ $state->value }}" @selected($status === $state->value)>{{ str($state->value)->replace('_', ' ')->headline() }}</option>@endforeach</select></label>
        <label for="application-age">At least this many days old<input id="application-age" class="hm-input" type="number" name="age" min="0" max="3650" value="{{ $age ?: '' }}"></label>
        <label for="application-domain">Domain<input id="application-domain" class="hm-input" name="domain" value="{{ $domain }}" autocomplete="off"></label>
        <label for="application-applicant">Applicant name or email<input id="application-applicant" class="hm-input" name="applicant" value="{{ $applicant }}" autocomplete="off"></label>
        <div class="wizard-actions full"><button class="hm-button-primary" type="submit">Apply filters</button><a class="hm-button-secondary" href="{{ route('admin.publisher-applications.index') }}">Clear filters</a></div>
    </form>
</article>

@if($applications->count() === 0)
    <x-empty-state title="No applications match these filters" description="Clear or broaden the filters to review other Publisher applications.">
        <a class="hm-button-secondary" href="{{ route('admin.publisher-applications.index') }}">Clear filters</a>
    </x-empty-state>
@else
    <div class="table-wrap" role="region" aria-label="Publisher application review queue" tabindex="0"><table><thead><tr><th scope="col">Applicant</th><th scope="col">Publisher / domain</th><th scope="col">Status</th><th scope="col">Submitted</th><th scope="col">Age</th><th scope="col">Action</th></tr></thead><tbody>
    @foreach($applications as $application)<tr><td>{{ $application->applicant->name }}<small class="table-note">{{ $application->applicant->email }}</small></td><td>{{ $application->publisher->display_name }}<small class="table-note">{{ $application->primary_domain }}</small></td><td><x-status-badge :status="$application->status" /></td><td>{{ $application->last_submitted_at ?: 'Draft only' }}</td><td>{{ $application->created_at->diffForHumans() }}</td><td><a class="section-anchor" href="{{ route('admin.publisher-applications.show', $application) }}">Review application</a></td></tr>@endforeach
    </tbody></table></div>{{ $applications->links() }}
@endif
@endsection
