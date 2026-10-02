@extends('layouts.admin')
@section('title', 'Historical GAM corrections')
@section('heading', 'Historical GAM corrections')
@section('content')
<div class="reports-page">
    <header class="report-page-heading">
        <div>
            <p class="eyebrow">PRIVATE ADMIN REVIEW</p>
            <h2>Review a historical revenue correction</h2>
            <p class="muted">Compare fresh exact-hostname Ad Exchange evidence with stored daily facts and their original revenue rules. Applying a reviewed candidate changes the selected historical reporting facts and allocations.</p>
        </div>
    </header>
    <p><a class="text-link" href="{{ route('admin.reporting.gam-comparison') }}">Back to historical previews</a> · <a class="text-link" href="{{ route('admin.reporting.index') }}">Back to reports</a></p>

    @if($errors->any())
        <section class="workspace-section notice" role="alert" aria-label="Correction could not proceed">
            @foreach($errors->all() as $error)<p class="error breakable">{{ $error }}</p>@endforeach
        </section>
    @endif

    <article class="workspace-section">
        <h3>Request a candidate for review</h3>
        <p>Choose up to 31 completed days within one calendar month and the existing binding’s effective dates, strictly before the existing forward reporting cutover. The exact registered hostname, selected ad unit, Google network timezone and USD reporting currency apply.</p>
        <form method="POST" action="{{ route('admin.reporting.gam-corrections.start') }}" class="form-stack">
            @csrf
            <div>
                <label for="gam-correction-site">Website</label>
                <select id="gam-correction-site" class="hm-input" name="site_id" required>
                    @forelse($sites as $site)
                        <option value="{{ $site->id }}" @selected(old('site_id', data_get($selected, 'context.site_id')) === $site->id)>{{ $site->primary_domain }}</option>
                    @empty
                        <option value="">No websites with an existing GAM reporting binding</option>
                    @endforelse
                </select>
            </div>
            <div class="form-grid">
                <label for="gam-correction-from">From<input id="gam-correction-from" class="hm-input" type="date" name="from" required value="{{ old('from', data_get($selected, 'context.from', now()->subMonthNoOverflow()->startOfMonth()->toDateString())) }}"></label>
                <label for="gam-correction-to">To<input id="gam-correction-to" class="hm-input" type="date" name="to" required value="{{ old('to', data_get($selected, 'context.to', now()->subMonthNoOverflow()->endOfMonth()->toDateString())) }}"></label>
            </div>
            <button type="submit" class="hm-button-secondary" @disabled($sites->isEmpty())>Request correction review</button>
        </form>
        <p class="muted">Requesting evidence does not apply a correction. A missing day, original fact or original rule blocks the entire candidate. No parent-domain, sibling-site, other-unit or alternative-metric substitution is allowed.</p>
    </article>

    <article class="workspace-section">
        <h3>Your recent candidates</h3>
        <p class="muted">Only candidates you requested are visible here. The latest 10 are listed.</p>
        @forelse($corrections as $candidate)
            <div class="compact-row status-row">
                <div>
                    <a class="text-link breakable" href="{{ route('admin.reporting.gam-corrections.show', $candidate->id) }}">{{ data_get($candidate->context, 'hostname', 'Unverified hostname') }} · {{ data_get($candidate->context, 'from') }} to {{ data_get($candidate->context, 'to') }}</a>
                    <span class="table-note">Requested {{ $candidate->created_at?->toIso8601String() }}</span>
                </div>
                <x-status-badge :status="$candidate->status" />
            </div>
        @empty
            <p class="muted">No correction candidates requested.</p>
        @endforelse
    </article>

    @if($selected)
        @php
            $context = $selected->context ?? [];
            $proposal = $selected->proposal ?? [];
            $days = $proposal['days'] ?? [];
            $flags = $proposal['flags'] ?? [];
            $expired = ! $selected->expires_at || $selected->expires_at->isPast();
            $money = fn ($minor) => $minor === null ? 'Unverified' : \App\Support\Money::formatMinor((int) $minor);
            $count = fn ($number) => $number === null ? 'Unverified' : number_format((int) $number);
            $facts = collect(data_get($selected->snapshot, 'facts', []))->keyBy('fact.id');
            $canApply = $selected->status === 'READY' && ! $expired && ($proposal['eligible'] ?? false)
                && ! $flags && $days && ! collect($days)->contains(fn ($day) => ! empty($day['flags']))
                && is_string($selected->digest) && preg_match('/\A[a-f0-9]{64}\z/', $selected->digest);
            $financialFields = [
                'gross_revenue_minor' => 'Gross revenue', 'demand_partner_deductions_minor' => 'Demand-partner deductions',
                'invalid_traffic_adjustments_minor' => 'Invalid-traffic adjustments', 'other_adjustments_minor' => 'Other adjustments',
                'net_revenue_minor' => 'Net revenue', 'publisher_earnings_minor' => 'Publisher earnings',
                'horus_earnings_minor' => 'Horus earnings', 'mcm_partner_earnings_minor' => 'MCM partner earnings',
            ];
            $counterFields = [
                'ad_requests' => 'Ad requests', 'matched_requests' => 'Responses served',
                'impressions' => 'Impressions', 'clicks' => 'Clicks',
                'active_view_viewable_impressions' => 'Active View viewable impressions',
                'active_view_measurable_impressions' => 'Active View measurable impressions',
            ];
        @endphp
        <article class="workspace-section" aria-labelledby="gam-correction-review-heading">
            <div class="section-heading">
                <h3 id="gam-correction-review-heading" class="breakable">{{ data_get($context, 'hostname', 'Unverified hostname') }}</h3>
                <x-status-badge :status="$selected->status" />
            </div>
            <p>{{ data_get($context, 'from') }} to {{ data_get($context, 'to') }} · {{ data_get($context, 'timezone', 'Unverified timezone') }} · {{ data_get($context, 'currency', 'Unverified currency') }} · Ad Exchange</p>
            <p>Selected ad unit {{ data_get($context, 'ad_unit_id', 'Unverified') }} · Network {{ data_get($context, 'network_code', 'Unverified') }} · Network base {{ data_get($context, 'network_currency', 'Unverified') }}</p>
            <p>Captured {{ data_get($context, 'captured_at', 'Unverified') }} · Review expires {{ $selected->expires_at?->toIso8601String() ?? 'Unverified' }}</p>
            <p class="breakable">Candidate {{ $selected->id }}</p>
            <p class="muted">Separate approved adjustments are excluded. Each day retains its original rule version and deductions. A correction requires the whole range to remain eligible when applied.</p>

            @if($selected->status === 'STARTING')
                <p class="notice" role="alert">The report request is starting or its outcome is uncertain. This attempt is not automatically resubmitted. No correction has been applied.</p>
            @elseif($selected->status === 'PENDING')
                @if($expired)
                    <p class="error" role="alert">This candidate has expired. Request and review fresh evidence before applying a correction.</p>
                @else
                    <p>Google is preparing the report. Wait at least 15 seconds between refreshes.</p>
                    <form method="POST" action="{{ route('admin.reporting.gam-corrections.poll', $selected->id) }}">
                        @csrf
                        <button type="submit" class="hm-button-secondary">Refresh status</button>
                    </form>
                @endif
            @elseif($selected->status === 'FAILED')
                <p class="error breakable" role="alert">Evidence could not be verified. No correction was applied. {{ $selected->error_code }}</p>
            @elseif($selected->status === 'SUPERSEDED')
                <p class="notice" role="status">This candidate was superseded by a request for fresh evidence and can no longer be applied. Its original evidence is retained. Open your latest candidate and review it before approving a correction.</p>
            @endif

            @if(in_array($selected->status, ['READY', 'BLOCKED', 'FAILED'], true))
                <section class="workspace-section" aria-label="Request another evidence review">
                    <p>Request fresh evidence to supersede this candidate. Its captured evidence is retained, and the new candidate requires another review before any correction can be applied.</p>
                    <form method="POST" action="{{ route('admin.reporting.gam-corrections.replace', $selected->id) }}">
                        @csrf
                        <button type="submit" class="hm-button-secondary">Request fresh evidence</button>
                    </form>
                </section>
            @endif

            @if($days)
                <p class="notice">{{ data_get($proposal, 'basis_notice', 'Legacy or unversioned amounts may differ in metric basis as well as site scope.') }}</p>
                <p>Fresh evidence retrieved {{ data_get($selected->job, 'completed_at', 'Unverified') }}. All displayed monetary amounts are USD.</p>
                @if($flags)
                    <div class="notice" role="alert"><strong>Entire candidate blocked</strong><ul>@foreach($flags as $flag)<li class="breakable">{{ $flag }}</li>@endforeach</ul></div>
                @elseif($selected->status === 'BLOCKED')
                    <p class="error" role="alert">The entire candidate is blocked. Review the daily flags and request new evidence once the cause is resolved.</p>
                @endif
                @if($expired && $selected->status !== 'APPLIED')
                    <p class="error" role="alert">This candidate has expired and cannot be applied. Request and review fresh evidence.</p>
                @endif
                @if(data_get($proposal, 'totals') !== null)
                <div class="table-wrap" role="region" aria-label="Correction totals in USD" tabindex="0">
                    <table>
                        <caption>Range totals from the captured proposal (USD)</caption>
                        <thead><tr><th scope="col">Allocation</th><th scope="col" style="text-align: right">Stored</th><th scope="col" style="text-align: right">Proposed</th></tr></thead>
                        <tbody>
                            @foreach(['gross' => 'Gross revenue', 'publisher' => 'Publisher earnings', 'horus' => 'Horus earnings'] as $key => $label)
                                <tr><th scope="row">{{ $label }}</th><td style="text-align: right">{{ $money(data_get($proposal, 'totals.stored_'.$key.'_minor')) }}</td><td style="text-align: right">{{ $money(data_get($proposal, 'totals.new_'.$key.'_minor')) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @else
                    <p class="muted">Whole-range totals are withheld because the candidate is blocked. Observed daily rows below are evidence, not a partial correction.</p>
                @endif
                <div class="table-wrap" role="region" aria-label="Daily correction comparison in USD" tabindex="0">
                    <table>
                        <caption>Every selected day: stored → proposed (USD). Missing evidence is never treated as zero.</caption>
                        <thead><tr><th scope="col">Date</th><th scope="col" style="text-align: right">Gross</th><th scope="col" style="text-align: right">Publisher</th><th scope="col" style="text-align: right">Horus</th><th scope="col">Evidence and finality</th><th scope="col">Original rule and review flags</th></tr></thead>
                        <tbody>
                            @foreach($days as $day)
                                @php
                                    $original = $facts->get($day['original_fact_id'] ?? '') ?? [];
                                    $stored = ($day['stored'] ?? null) === null ? [] : array_merge(data_get($original, 'fact', []), $day['stored']);
                                    $fresh = $day['fresh'] ?? null;
                                @endphp
                                <tr>
                                    <th scope="row">{{ $day['date'] }}</th>
                                    <td style="text-align: right">{{ $money(data_get($day, 'stored.gross_revenue_minor')) }} → {{ $money(data_get($day, 'fresh.gross_revenue_minor')) }}</td>
                                    <td style="text-align: right">{{ $money(data_get($day, 'stored.publisher_earnings_minor')) }} → {{ $money(data_get($day, 'projected.publisher_earnings_minor')) }}</td>
                                    <td style="text-align: right">{{ $money(data_get($day, 'stored.horus_earnings_minor')) }} → {{ $money(data_get($day, 'projected.horus_earnings_minor')) }}</td>
                                    <td>
                                        @if($fresh === null)
                                            <strong class="error">Missing exact-site row; not zero</strong>
                                        @elseif(data_get($fresh, 'gross_revenue_minor') === 0)
                                            <strong>Observed exact-site zero revenue</strong>
                                        @else
                                            <strong>Exact-site row observed</strong>
                                        @endif
                                        <span class="table-note">Finality: {{ $day['original_finality'] ?? 'Unverified' }} → {{ $day['proposed_finality'] ?? 'Unverified' }}</span>
                                        <span class="table-note">Impressions: {{ $count(data_get($day, 'stored.impressions')) }} → {{ $count(data_get($fresh, 'impressions')) }}</span>
                                        <span class="table-note">Clicks: {{ $count(data_get($stored, 'clicks')) }} → {{ $count(data_get($fresh, 'clicks')) }}</span>
                                    </td>
                                    <td>
                                        <span class="breakable">Rule version {{ $day['original_rule_version_id'] ?? 'Unverified' }}</span>
                                        <span class="table-note">Basis: {{ implode(', ', $day['stored_metric_bases'] ?? []) ?: 'Unverified' }} → {{ $day['fresh_metric_basis'] ?? 'Unverified' }}</span>
                                        @if($day['basis_change'] ?? false)<p>Metric basis changed or unversioned; difference is not scope-only.</p>@endif
                                        @if($day['flags'] ?? [])<ul>@foreach($day['flags'] as $flag)<li class="error">{{ $flag }}</li>@endforeach</ul>@else<span class="table-note">Daily evidence checks passed</span>@endif
                                        <details>
                                            <summary class="text-link">Allocation details for {{ $day['date'] }}</summary>
                                            <p class="breakable">Original fact {{ $day['original_fact_id'] ?? 'Unverified' }}</p>
                                            <p>Original shares (basis points): Publisher {{ data_get($original, 'rule_version.publisher_share_bp', 'Unverified') }} · Horus {{ data_get($original, 'rule_version.horus_share_bp', 'Unverified') }} · MCM {{ data_get($original, 'rule_version.mcm_partner_share_bp', 'Unverified') }}</p>
                                            @foreach($financialFields as $field => $label)
                                                <span class="table-note">{{ $label }}: {{ $money(data_get($stored, $field)) }} → {{ $money(data_get($day, 'projected.'.$field)) }}</span>
                                            @endforeach
                                            @foreach($counterFields as $field => $label)
                                                <span class="table-note">{{ $label }}: {{ $count(data_get($stored, $field)) }} → {{ $count(data_get($fresh, $field)) }}</span>
                                            @endforeach
                                            <p>Legacy Total counters are not preserved as Ad Exchange evidence. On application, unfilled impressions become unavailable; video starts and completed views reset to zero. Unfilled requests and derived rates are recalculated from the fresh Ad Exchange counters.</p>
                                            <span class="table-note">Stored unfilled impressions: {{ $count(data_get($stored, 'unfilled_impressions')) }} · Video starts: {{ $count(data_get($stored, 'video_starts')) }} · Completed views: {{ $count(data_get($stored, 'completed_views')) }}</span>
                                        </details>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if($canApply)
                <section class="workspace-section" aria-labelledby="gam-correction-approval-heading">
                    <h3 id="gam-correction-approval-heading">Approve this reviewed correction</h3>
                    <p>This applies all {{ count($days) }} reviewed days together using the captured amounts and original rule versions. Review the scope, basis changes, finality transitions and publisher/Horus allocation before continuing.</p>
                    <p class="breakable">Evidence digest: {{ $selected->digest }}</p>
                    <form method="POST" action="{{ route('admin.reporting.gam-corrections.apply', $selected->id) }}" class="form-stack">
                        @csrf
                        <input type="hidden" name="digest" value="{{ $selected->digest }}">
                        <label for="gam-correction-confirm"><span><input id="gam-correction-confirm" type="checkbox" name="confirm_review" value="1" required> I reviewed every day, the exact hostname and ad unit, USD currency, original rules, basis changes and finality transitions, and approve this correction.</span></label>
                        <label for="gam-correction-reason">Reason for approval<textarea id="gam-correction-reason" class="hm-input" name="reason" required minlength="12" maxlength="2000" rows="3">{{ old('reason') }}</textarea></label>
                        <button type="submit" class="hm-button-primary">Apply reviewed correction</button>
                    </form>
                    <p class="muted">Eligibility and evidence are checked again when you apply. A repeated submission of this candidate returns the existing receipt.</p>
                </section>
            @elseif($selected->status === 'READY' && ! $expired)
                <p class="error" role="alert">This candidate lacks complete eligible evidence and cannot be applied.</p>
            @endif

            @if($selected->status === 'APPLIED')
                @php($receipt = $selected->receipt ?? [])
                <section class="workspace-section" aria-labelledby="gam-correction-receipt-heading">
                    <h3 id="gam-correction-receipt-heading">Applied correction receipt</h3>
                    <p role="status">This correction was applied {{ data_get($receipt, 'applied_at', $selected->applied_at?->toIso8601String()) }}. The recorded outcome below is retained as private audit evidence.</p>
                    <dl>
                        <dt>Approved by</dt><dd class="breakable">{{ data_get($receipt, 'approved_by', 'Unverified') }}</dd>
                        <dt>Reason</dt><dd class="breakable">{{ data_get($receipt, 'reason', 'Unverified') }}</dd>
                        <dt>Import</dt><dd class="breakable">{{ data_get($receipt, 'report_import_job_id', 'Unverified') }}</dd>
                        <dt>Reconciliation</dt><dd class="breakable">{{ data_get($receipt, 'reconciliation_run_id', 'Unverified') }}</dd>
                        <dt>Applied digest</dt><dd class="breakable">{{ data_get($receipt, 'digest', 'Unverified') }}</dd>
                        <dt>Before evidence hash</dt><dd class="breakable">{{ data_get($receipt, 'before_hash', 'Unverified') }}</dd>
                        <dt>After evidence hash</dt><dd class="breakable">{{ data_get($receipt, 'after_hash', 'Unverified') }}</dd>
                    </dl>
                    @foreach(['before' => 'Before application', 'after' => 'After application'] as $stage => $label)
                        @if(data_get($receipt, $stage.'.facts'))
                            <details>
                                <summary class="text-link">{{ $label }}: recorded daily evidence</summary>
                                <div class="table-wrap" role="region" aria-label="{{ $label }} daily evidence" tabindex="0">
                                    <table>
                                        <caption>{{ $label }} (USD)</caption>
                                        <thead><tr><th scope="col">Date / fact</th><th scope="col" style="text-align: right">Gross</th><th scope="col" style="text-align: right">Publisher</th><th scope="col" style="text-align: right">Horus</th><th scope="col">Finality / rule</th></tr></thead>
                                        <tbody>
                                            @foreach(data_get($receipt, $stage.'.facts', []) as $row)
                                                <tr>
                                                    <th scope="row">{{ data_get($row, 'fact.report_date', 'Unverified') }}<span class="table-note breakable">{{ data_get($row, 'fact.id', 'Unverified') }}</span></th>
                                                    <td style="text-align: right">{{ $money(data_get($row, 'fact.gross_revenue_minor')) }}</td>
                                                    <td style="text-align: right">{{ $money(data_get($row, 'fact.publisher_earnings_minor')) }}</td>
                                                    <td style="text-align: right">{{ $money(data_get($row, 'fact.horus_earnings_minor')) }}</td>
                                                    <td>{{ data_get($row, 'fact.finality', 'Unverified') }}<span class="table-note breakable">{{ data_get($row, 'fact.revenue_rule_version_id', 'Unverified') }}</span></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    @endforeach
                </section>
            @endif
        </article>
    @endif
</div>
@endsection
