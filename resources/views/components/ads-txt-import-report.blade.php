@php($report = session('ads_txt_import_report'))
@if(is_array($report))
<section class="import-report" aria-labelledby="ads-txt-import-report-heading">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Import result</p>
            <h3 id="ads-txt-import-report-heading">{{ $report['created'] ?? 0 }} created@if(isset($report['updated'])) · {{ $report['updated'] }} updated@endif @if(isset($report['reactivated']))· {{ $report['reactivated'] }} reactivated@endif · {{ $report['skipped'] ?? 0 }} existing · {{ $report['invalid_total'] ?? count($report['invalid'] ?? []) }} invalid skipped</h3>
        </div>
        <span class="status-badge {{ empty($report['invalid_total']) ? 'status-badge-success' : 'status-badge-warning' }}">{{ empty($report['invalid_total']) ? 'COMPLETE' : 'REVIEW NEEDED' }}</span>
    </div>
    <p class="muted">{{ $report['total_lines'] ?? 0 }} input lines · {{ $report['ignored'] ?? 0 }} comments/directives ignored · {{ $report['duplicates'] ?? 0 }} exact duplicates skipped@if(!empty($report['superseded'])) · {{ $report['superseded'] }} earlier conflicting rows replaced by the latest pasted value@endif.</p>
    @if(!empty($report['invalid']))
        <details open>
            <summary>Invalid rows{{ ($report['invalid_total'] ?? 0) > count($report['invalid']) ? ' · first '.count($report['invalid']).' shown' : '' }}</summary>
            <div class="compact-list">
                @foreach($report['invalid'] as $failure)
                    <div class="finding finding-danger"><strong>Line {{ $failure['line'] }}</strong><code>{{ $failure['content'] }}</code><span>{{ $failure['message'] }}</span></div>
                @endforeach
            </div>
        </details>
    @endif
</section>
@endif
