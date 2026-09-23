<x-control-plane.workspace-tabs :items="[
    ['label' => 'Earnings overview', 'href' => route('publisher.finance.overview')],
    ['label' => 'Monthly statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payment details', 'href' => route('publisher.finance.payment-method.edit')],
    ['label' => 'Payouts', 'href' => route('publisher.finance.payouts.index')],
]" label="Earnings and Payments sections" />
