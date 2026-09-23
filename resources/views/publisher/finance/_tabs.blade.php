<x-control-plane.workspace-tabs :items="[
    ['label' => 'Earnings', 'href' => route('publisher.finance.overview')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payouts', 'href' => route('publisher.finance.payouts.index')],
    ['label' => 'Payment method', 'href' => route('publisher.finance.payment-method.edit')],
]" label="Earnings and Payments sections" />
