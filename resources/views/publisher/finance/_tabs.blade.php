<x-control-plane.workspace-tabs :items="[
    ['label' => 'Reports', 'href' => route('publisher.reporting.index'), 'visible' => auth()->user()->hasPermission('reporting.publisher.view')],
    ['label' => 'Finance', 'href' => route('publisher.finance.overview'), 'visible' => auth()->user()->hasPermission('finance.publisher.view_own')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index'), 'visible' => auth()->user()->hasPermission('finance.publisher.view_own')],
    ['label' => 'Payment details', 'href' => route('publisher.finance.payment-method.edit'), 'visible' => auth()->user()->hasPermission('finance.publisher.view_own')],
    ['label' => 'Payout history', 'href' => route('publisher.finance.payouts.index'), 'visible' => auth()->user()->hasPermission('finance.publisher.view_own')],
]" label="Reports and earnings sections" />
