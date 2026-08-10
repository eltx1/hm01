<x-control-plane.workspace-tabs :items="[
    ['label' => 'Overview', 'href' => route('publisher.finance.overview')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payment Method', 'href' => route('publisher.finance.payment-method.edit')],
    ['label' => 'Payout History', 'href' => route('publisher.finance.payouts.index')],
]" label="Earnings and Payments sections" />
