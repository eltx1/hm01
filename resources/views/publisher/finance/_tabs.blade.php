<x-control-plane.workspace-tabs :items="[
    ['label' => 'Reports & earnings', 'href' => route('publisher.finance.overview')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payment details', 'href' => route('publisher.finance.payment-method.edit')],
    ['label' => 'Payout history', 'href' => route('publisher.finance.payouts.index')],
]" label="Reports and earnings sections" />
