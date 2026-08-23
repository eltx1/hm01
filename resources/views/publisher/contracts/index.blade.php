@extends('layouts.admin')
@section('title', 'Commercial terms')
@section('heading', 'Commercial terms')
@section('content')<div class="table-wrap"><table><thead><tr><th>Reference</th><th>Status</th><th>Effective term</th><th>Default share</th><th></th></tr></thead><tbody>@forelse($contracts as $contract)<tr><td>{{ $contract->contract_reference }}</td><td>{{ $contract->status->value }}</td><td>{{ $contract->starts_at?->toDateString() ?: 'Not set' }} – {{ $contract->ends_at?->toDateString() ?: 'Open' }}</td><td>{{ $contract->revenue_share_percent }}%</td><td><a href="{{ route('publisher.contracts.show', $contract) }}">View terms</a></td></tr>@empty<tr><td colspan="5">No commercial terms are available yet.</td></tr>@endforelse</tbody></table></div>@endsection
