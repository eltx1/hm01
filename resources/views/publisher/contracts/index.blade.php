@extends('layouts.admin')
@section('title', 'Contracts')
@section('heading', 'Publisher contracts')
@section('content')<div class="table-wrap"><table><thead><tr><th>Reference</th><th>Status</th><th>Term</th><th>Revenue share</th><th></th></tr></thead><tbody>@forelse($contracts as $contract)<tr><td>{{ $contract->contract_reference }}</td><td>{{ $contract->status->value }}</td><td>{{ $contract->starts_at?->toDateString() }} – {{ $contract->ends_at?->toDateString() }}</td><td>{{ $contract->revenue_share_percent }}%</td><td><a href="{{ route('publisher.contracts.show', $contract) }}">View agreement</a></td></tr>@empty<tr><td colspan="5">No contract is available yet.</td></tr>@endforelse</tbody></table></div>@endsection
