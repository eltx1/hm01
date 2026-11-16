@extends('layouts.admin')
@section('title', 'Website attention queue')
@section('heading', 'Website attention queue')
@section('content')
<section><h2>Recorded evidence requiring review</h2><p>Active websites only. This page reads stored monitor records; opening it does not run checks, republish settings or change advertising permission. Missing or stale evidence is not proof of an outage. Optional engines are not reclassified here.</p>
<form method="GET" class="inline-form"><label>Evidence<select class="hm-select" name="evidence">@foreach(['findings'=>'Has stored findings','stale'=>'Has stale or invalid timestamps','missing'=>'No stored checks','publication'=>'Current publication failure or retry','ads_txt'=>'Latest primary-domain ads.txt failure','synthetic'=>'Latest production probe failure'] as $value=>$label)<option value="{{ $value }}" @selected($filter===$value)>{{ $label }}</option>@endforeach</select></label><button class="hm-button-primary">Filter</button></form></section>
@forelse($rows as $row)
<section><h2>{{ $row['site']->display_name }}</h2><p>{{ $row['summary']['label'] }} · {{ $row['summary']['findings'] }} stored finding(s)</p>
<p class="muted">Source attempts are historical observations, and do not establish current installation, current seller records, visitor rendering or protection outcomes. A newer successful observation replaces an older failure for the same source. At most 10 probe source rows per website are shown. No source check runs on this page.</p>
<p class="muted">Source: persisted monetization monitor. Oldest observation (UTC): {{ $row['summary']['oldest_observation_utc'] ?? 'Unknown' }}</p>
<p><strong>{{ $row['publication']['label'] }}</strong> · Latest queued version: {{ $row['publication']['latest_version'] ?? 'None' }} · Confirmed production version: {{ $row['publication']['published_version'] ?? 'None' }}</p><p>{{ $row['publication']['detail'] }}</p>
<div class="table-wrap"><table><thead><tr><th>Check</th><th>Recorded state</th><th>Observed (UTC)</th></tr></thead><tbody>
@foreach($row['sources'] as $source)<tr><td>{{ $source['label'] }}</td><td>{{ $source['status'] }} (recorded)</td><td>{{ $source['time'] ?? 'Unknown' }}</td></tr>@endforeach
@forelse($row['checks'] as $check)<tr><td>{{ $check['label'] }}</td><td>{{ $check['status'] }}</td><td>{{ $check['observed_at'] ?? 'Unknown' }}</td></tr>@empty<tr><td colspan="3">No stored evidence.</td></tr>@endforelse
</tbody></table></div><a class="section-anchor" href="{{ route('admin.sites.show', $row['site']) }}#monetization-health">Review site evidence and existing controls</a></section>
@empty<section><p>No websites match this evidence filter. This does not establish health across all sources.</p></section>@endforelse
{{ $sites->links() }}
@endsection
