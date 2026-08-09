@extends('layouts.admin')
@section('title', 'Ads.txt & Compliance')
@section('heading', 'Ads.txt & Compliance')
@section('content')
<section class="hero"><div><p class="eyebrow">Publisher compliance</p><h2>Exact files, clear remediation</h2><p>Publish the exact canonical content at <code>/ads.txt</code> on each verified website. Checks read only your authorized domains and never reveal private demand credentials.</p></div></section>

@forelse($sites as $site)
@php($summary = $summaries[$site->id])
<article class="workspace-section publisher-compliance-card">
    <div class="workspace-heading"><div><p class="eyebrow">{{ $site->display_name }}</p><h2>{{ $site->primary_domain }}/ads.txt</h2><div class="status-row"><x-status-badge :status="$summary['status']" /><span class="muted">Last check: {{ $summary['last_checked']?->diffForHumans() ?: 'never' }}</span></div></div><div class="status-row"><button class="hm-button-primary" type="button" data-copy-target="publisher-ads-txt-{{ $site->id }}">Copy All</button><a class="hm-button-secondary" href="{{ route('publisher.ads-txt.download', $site) }}">Download</a>@if(auth()->user()->hasPermission('publisher.ads_txt.verify_own'))<form method="POST" action="{{ route('publisher.ads-txt.verify', $site) }}">@csrf<button class="hm-button-secondary">Check Again</button></form>@endif</div></div>
    <p>{{ $summary['action'] }}</p><pre id="publisher-ads-txt-{{ $site->id }}" class="compliance-code">{{ $summary['canonical']['content'] }}</pre>
    <div class="compliance-diff-grid">
        <div><h3>Correct records</h3>@forelse($summary['comparison']['correct'] ?? [] as $item)<code class="record-line record-correct">{{ $item['canonical'] }}</code>@empty<p class="muted">Nothing confirmed yet.</p>@endforelse</div>
        <div><h3>Missing required records</h3>@forelse($summary['comparison']['missing'] ?? [] as $item)<code class="record-line record-missing">{{ $item['canonical'] }}</code>@empty<p class="muted">No missing seller records.</p>@endforelse @foreach($summary['comparison']['missing_directives'] ?? [] as $item)<code class="record-line record-missing">{{ $item['canonical'] }}</code>@endforeach</div>
        <div><h3>Invalid or conflicting</h3>@forelse(array_merge($summary['comparison']['invalid'] ?? [], $summary['comparison']['conflicts'] ?? []) as $item)<div class="finding finding-danger"><span>{{ $item['message'] }}</span>@if(isset($item['content']))<code>{{ $item['content'] }}</code>@endif</div>@empty<p class="muted">No invalid or conflicting declarations.</p>@endforelse</div>
        <div><h3>Additional live records</h3>@forelse($summary['comparison']['additional'] ?? [] as $item)<code class="record-line">{{ $item['canonical'] }}</code>@empty<p class="muted">No additional live records.</p>@endforelse</div>
    </div>
</article>
@empty<article><p class="muted">No publisher websites are configured yet.</p></article>@endforelse
@endsection
