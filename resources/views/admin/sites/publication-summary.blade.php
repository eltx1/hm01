<section class="workspace-section" aria-labelledby="publication-summary-title" data-publication-state="{{ $publicationSummary['state'] }}">
    <div class="workspace-heading">
        <div><p class="eyebrow">Configuration delivery</p><h2 id="publication-summary-title">Saved and published configuration</h2></div>
        <strong>{{ $publicationSummary['label'] }}</strong>
    </div>
    <p>{{ $publicationSummary['detail'] }}</p>
    <dl>
        <dt>Latest queued production version</dt><dd>{{ $publicationSummary['latest_version'] ?? 'None' }}</dd>
        <dt>Confirmed production version</dt><dd>{{ $publicationSummary['published_version'] ?? 'None' }}</dd>
        <dt>Production confirmation time (UTC)</dt><dd>{{ $publicationSummary['published_at'] ?? 'Not recorded' }}</dd>
    </dl>
    @if(auth()->user()->hasPermission('operations.view'))
        <a class="section-anchor" href="{{ route('admin.operations.index') }}">Review delivery in Operations</a>
    @endif
</section>
