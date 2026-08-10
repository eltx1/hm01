@php
    $items = [
        ['label' => 'Overview', 'href' => route('admin.finance.overview'), 'visible' => auth()->user()->hasPermission('finance.operations.view')],
        ['label' => 'Financial Periods', 'href' => route('admin.finance.periods.index'), 'visible' => auth()->user()->hasPermission('finance.operations.view')],
        ['label' => 'Publisher Statements', 'href' => route('admin.finance.statements.index'), 'visible' => auth()->user()->hasPermission('finance.publisher.view')],
        ['label' => 'Payouts', 'href' => route('admin.finance.payouts.index'), 'visible' => auth()->user()->hasPermission('finance.payments.view')],
        ['label' => 'Payment Profiles', 'href' => route('admin.finance.payment-profiles.index'), 'visible' => auth()->user()->hasPermission('finance.payment_profiles.verify')],
        ['label' => 'Revenue Rules', 'href' => route('admin.finance.revenue-rules.index'), 'visible' => auth()->user()->hasPermission('finance.operations.view')],
        ['label' => 'Adjustments', 'href' => route('admin.finance.adjustments.index'), 'visible' => auth()->user()->hasPermission('finance.operations.view')],
        ['label' => 'Reconciliation', 'href' => route('admin.finance.reconciliation.index'), 'visible' => auth()->user()->hasPermission('finance.operations.view')],
    ];
    if (auth()->user()->hasPermission('billing.advertiser.view')) {
        $items[] = ['label' => 'Advertiser Billing', 'href' => route('admin.advertisers.index'), 'visible' => true];
    }
@endphp
<x-control-plane.workspace-tabs :items="$items" label="Finance Operations sections" />
