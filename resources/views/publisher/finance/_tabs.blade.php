<x-control-plane.workspace-tabs :items="[
    ['label' => 'Reports & Earnings', 'href' => route('publisher.finance.overview')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payment Method', 'href' => route('publisher.finance.payment-method.edit')],
    ['label' => 'Payouts', 'href' => route('publisher.finance.payouts.index')],
]" label="Reports, earnings and payments sections" />
