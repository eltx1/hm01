<x-control-plane.workspace-tabs :items="[
    ['label' => 'Summary', 'href' => route('publisher.finance.overview')],
    ['label' => 'Statements', 'href' => route('publisher.finance.statements.index')],
    ['label' => 'Payment Method', 'href' => route('publisher.finance.payment-method.edit')],
    ['label' => 'Payouts', 'href' => route('publisher.finance.payouts.index')],
]" label="Reports and earnings sections" />
