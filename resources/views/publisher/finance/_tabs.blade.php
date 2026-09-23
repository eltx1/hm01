<x-control-plane.workspace-tabs :items="[
    ['label' => 'Reports & earnings', 'href' => route('publisher.finance.overview')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payment method', 'href' => route('publisher.finance.payment-method.edit')],
    ['label' => 'Payouts', 'href' => route('publisher.finance.payouts.index')],
]" label="Earnings and payout sections" />
