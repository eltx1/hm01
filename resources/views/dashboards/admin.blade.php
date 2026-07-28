@extends('layouts.admin')
@section('title', 'Administrator dashboard')
@section('heading', 'Horus Media overview')
@section('navigation')
<a class="active" href="{{ route('dashboard') }}">Overview</a><span>Publishers</span><span>Advertisers</span><span>Users</span><span>Access control</span><span>Audit log</span>
@endsection
@section('content')
<section class="metric-grid">
@foreach ([['Total publishers',$totalPublishers],['Total websites',$totalWebsites],['Total advertisers',$totalAdvertisers],['Active campaigns',$activeCampaigns],['Estimated monthly revenue','$'.number_format($estimatedMonthlyRevenue, 2)]] as [$label,$value])
<article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
@endforeach
</section>
<section class="split-grid">
<article><h2>Recent system activity</h2><p class="muted">Authentication and operational activity is captured in structured logs.</p><h3>Failed scheduled jobs</h3><p class="metric-small">{{ $failedJobs->count() }}</p></article>
<article><h2>Recent audit events</h2>@forelse($auditEvents as $event)<div class="event"><strong>{{ $event->event }}</strong><span>{{ $event->created_at }}</span></div>@empty<p class="muted">No audit events yet.</p>@endforelse</article>
</section>
@endsection
