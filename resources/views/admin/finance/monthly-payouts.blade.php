@extends('layouts.admin')
@section('title', 'Monthly payouts')
@section('heading', 'Monthly payouts')
@section('content')
@include('admin.finance._tabs')
@php
    $money = fn (int $minor): string => \App\Support\Money::formatMinor($minor);
    $monthLabel = fn (string $key): string => \Carbon\CarbonImmutable::createFromFormat('!Y-m', $key)->format('F Y');
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">What to pay each publisher</p>
        <h2>{{ $monthLabel($summary['month']) }}</h2>
        <p>Gross revenue, the publisher's net earnings and the Horus commission for this calendar month only ({{ $summary['from'] }} to {{ $summary['to'] }}). Every amount comes from the daily ledger by its own date, the same figures each publisher sees in Reports &amp; earnings. No other month is added.</p>
    </div>
</section>

<form method="GET" action="{{ route('admin.finance.monthly-payouts') }}" class="inline-form workspace-section" aria-label="Choose a month">
    <label for="payout-month" class="sr-only">Month</label>
    <select id="payout-month" name="month" class="hm-input">
        @foreach($months as $option)
            <option value="{{ $option }}" @selected($option === $summary['month'])>{{ $monthLabel($option) }}@if($loop->first) (in progress)@endif</option>
        @endforeach
    </select>
    <button class="hm-button-secondary" type="submit">Show month</button>
    <a class="hm-button-primary" href="{{ route('admin.finance.monthly-payouts.csv', ['month' => $summary['month']]) }}">Download CSV</a>
</form>

@if(! $summary['complete'])
    <div class="notice" role="status">This month is still in progress. Amounts will keep changing until the month ends and Google finalizes its reports.</div>
@elseif($summary['has_estimates'])
    <div class="notice" role="status">Some amounts are still estimates awaiting Google's final figures. Rows marked “Includes estimates” can still change.</div>
@endif
@if($summary['mismatches'] > 0)
    <div class="notice error" role="alert">{{ $summary['mismatches'] }} {{ \Illuminate\Support\Str::plural('statement', $summary['mismatches']) }} for this month {{ $summary['mismatches'] === 1 ? 'does' : 'do' }} not match the ledger. The statement was generated before later data arrived. Review it before paying.</div>
@endif

@if($summary['rows']->isEmpty())
    <x-empty-state title="No publisher earnings for this month" description="No reported revenue exists for any publisher in this month. Choose another month." />
@else
    <section class="metric-grid workspace-section" aria-label="Month totals">
        @foreach($summary['totals'] as $total)
            <article><p class="eyebrow">Gross revenue · {{ $total['currency'] }}</p><strong class="metric">{{ $money($total['gross_minor']) }}</strong><span class="muted">{{ $total['publishers'] }} {{ \Illuminate\Support\Str::plural('publisher', $total['publishers']) }}</span></article>
            <article><p class="eyebrow">Pay to publishers · {{ $total['currency'] }}</p><strong class="metric">{{ $money($total['publisher_minor']) }}</strong><span class="muted">Publisher net earnings</span></article>
            <article><p class="eyebrow">Horus commission · {{ $total['currency'] }}</p><strong class="metric">{{ $money($total['horus_minor']) }}</strong><span class="muted">Your share of gross</span></article>
            <article><p class="eyebrow">Other deductions · {{ $total['currency'] }}</p><strong class="metric">{{ $money($total['other_minor']) }}</strong><span class="muted">Partners, invalid traffic and adjustments</span></article>
        @endforeach
    </section>

    <article class="workspace-section">
        <div class="workspace-heading"><div><p class="eyebrow">One row per publisher</p><h2>Publisher payouts for {{ $monthLabel($summary['month']) }}</h2></div></div>
        <div class="table-wrap" role="region" aria-label="Publisher payouts for {{ $monthLabel($summary['month']) }}" tabindex="0">
            <table>
                <caption class="sr-only">Gross revenue, publisher net earnings and Horus commission per publisher</caption>
                <thead><tr>
                    <th scope="col">Publisher</th>
                    <th scope="col" class="numeric">Gross</th>
                    <th scope="col" class="numeric">Publisher net <small>(pay this)</small></th>
                    <th scope="col" class="numeric">Horus commission</th>
                    <th scope="col" class="numeric">Other</th>
                    <th scope="col">Data</th>
                    <th scope="col">Statement</th>
                    <th scope="col">Payment details</th>
                </tr></thead>
                <tbody>
                @foreach($summary['rows'] as $row)
                    <tr data-publisher="{{ $row['publisher_id'] }}">
                        <th scope="row"><a href="{{ route('admin.publishers.show', $row['publisher_id']) }}">{{ $row['publisher'] }}</a><span class="table-note">{{ $row['sites'] }} {{ \Illuminate\Support\Str::plural('website', $row['sites']) }} · {{ $row['currency'] }}</span></th>
                        <td class="money">{{ $money($row['gross_minor']) }}</td>
                        <td class="money"><strong>{{ $money($row['publisher_minor']) }}</strong>@if($row['video_publisher_minor'] !== 0)<span class="table-note">incl. video {{ $money($row['video_publisher_minor']) }}</span>@endif</td>
                        <td class="money">{{ $money($row['horus_minor']) }}</td>
                        <td class="money">{{ $money($row['other_minor']) }}</td>
                        <td>
                            @if($row['days'] === 0)<x-status-badge status="NO_DATA" />
                            @elseif($row['final'])<x-status-badge status="FINALIZED" /><span class="table-note">{{ $row['days'] }} days</span>
                            @else<span class="status-badge status-badge-warning">Includes estimates</span><span class="table-note">{{ $money($row['estimated_publisher_minor']) }} estimated</span>@endif
                        </td>
                        <td>
                            @if($row['statement'])
                                <x-status-badge :status="$row['statement']['status']" />
                                @if(! $row['statement']['matches_ledger'])<span class="table-note field-error">Statement month earnings {{ $money($row['statement']['month_earnings_minor']) }} ≠ ledger</span>@endif
                                @if($row['statement']['carried_in_minor'] !== 0)<span class="table-note">+ {{ $money($row['statement']['carried_in_minor']) }} carried in from earlier months</span>@endif
                                @if($row['statement']['affiliate_minor'] !== 0)<span class="table-note">+ {{ $money($row['statement']['affiliate_minor']) }} referral commission</span>@endif
                                <span class="table-note">Balance due {{ $money($row['statement']['balance_due_minor']) }} · paid {{ $money($row['statement']['paid_minor']) }}</span>
                            @else
                                <span class="muted">Not generated yet</span>
                            @endif
                        </td>
                        <td>
                            <x-status-badge :status="$row['payment_details'] === 'MISSING' ? 'MISSING' : $row['payment_details']" />
                            @if($row['payment_method'])<span class="table-note">{{ \App\Support\DisplayLabel::from($row['payment_method']) }}</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                @foreach($summary['totals'] as $total)
                    <tr>
                        <th scope="row">Total · {{ $total['currency'] }}</th>
                        <td class="money"><strong>{{ $money($total['gross_minor']) }}</strong></td>
                        <td class="money"><strong>{{ $money($total['publisher_minor']) }}</strong></td>
                        <td class="money"><strong>{{ $money($total['horus_minor']) }}</strong></td>
                        <td class="money"><strong>{{ $money($total['other_minor']) }}</strong></td>
                        <td colspan="3"></td>
                    </tr>
                @endforeach
                </tfoot>
            </table>
        </div>
        <p class="report-footnote muted">Gross = publisher net + Horus commission + other (demand-partner deductions, invalid traffic, adjustments and MCM partner shares). “Carried in from earlier months” are unpaid balances that were below the payment threshold; they belong to their own months and are shown only beside the statement, never added to this month's figures.</p>
    </article>
@endif
@endsection
