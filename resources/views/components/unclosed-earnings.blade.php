@props(['periods'])
@if($periods->isNotEmpty())
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Previous months</p><h2>Earnings awaiting monthly close</h2></div></div>
    <p class="muted">Publisher earnings after the platform share. Finance must complete reconciliation and monthly close before these amounts become statement balances.</p>
    <div class="table-wrap"><table>
        <thead><tr><th>Period</th><th>Currency</th><th>Finalized source earnings</th><th>Estimates awaiting finalization</th><th>Status</th></tr></thead>
        <tbody>@foreach($periods as $period)
            <tr><td>{{ $period['period_key'] }}</td><td>{{ $period['currency'] }}</td>
                <td class="money">{{ \App\Support\Money::formatMinor($period['finalized_earnings_minor']) }}</td>
                <td class="money">{{ \App\Support\Money::formatMinor($period['estimated_earnings_minor']) }}</td>
                <td>Awaiting monthly close</td></tr>
        @endforeach</tbody>
    </table></div>
</article>
@endif
