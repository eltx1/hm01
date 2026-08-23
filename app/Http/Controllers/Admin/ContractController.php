<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Services\Audit\AuditRecorder;
use App\Services\Contracts\ContractLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function index(Publisher $publisher): View
    {
        return view('admin.contracts.index', ['publisher' => $publisher, 'contracts' => $publisher->contracts()->latest()->get()]);
    }

    public function create(Publisher $publisher): View
    {
        $contract = new PublisherContract([
            'contract_reference' => 'HM-'.now()->format('Y').'-'.str_pad((string) ($publisher->contracts()->count() + 1), 3, '0', STR_PAD_LEFT),
            'starts_at' => now()->toDateString(),
            'revenue_share_percent' => 70,
            'payment_threshold' => 100,
            'currency' => 'USD',
            'payment_terms' => 'NET_30',
        ]);

        return view('admin.contracts.form', ['publisher' => $publisher, 'contract' => $contract, 'allowedStatuses' => []]);
    }

    public function store(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $contract = PublisherContract::create(array_merge($this->validated($request, $publisher), [
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'created_by' => $request->user()->id,
            'status' => ContractStatus::Draft,
        ]));
        $audit->record('publisher_contract.created', $publisher->organization_id, $request->user(), $contract, newValues: $contract->only([
            'contract_reference', 'revenue_share_percent', 'payment_threshold', 'status',
        ]));

        return redirect()->route('admin.publishers.contracts.edit', [$publisher, $contract])
            ->with('status', 'Commercial terms created. Review and activate them when ready.');
    }

    public function edit(Publisher $publisher, PublisherContract $contract, ContractLifecycleService $lifecycle): View
    {
        $this->belongsTo($publisher, $contract);

        return view('admin.contracts.form', [
            'publisher' => $publisher,
            'contract' => $contract,
            'allowedStatuses' => $lifecycle->allowedTransitions($contract->status),
        ]);
    }

    public function update(
        Request $request,
        Publisher $publisher,
        PublisherContract $contract,
        AuditRecorder $audit,
        ContractLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->belongsTo($publisher, $contract);
        $before = $contract->only([
            'contract_reference', 'starts_at', 'ends_at', 'auto_renews', 'revenue_share_percent',
            'payment_threshold', 'payment_terms', 'internal_notes',
        ]);
        $contract->update($this->validated($request, $publisher, $contract));
        $audit->record(
            'publisher_contract.updated',
            $publisher->organization_id,
            $request->user(),
            $contract,
            $before,
            $contract->only(array_keys($before)),
        );
        $lifecycle->syncActiveTerms($contract, $request->user());

        return back()->with('status', 'Commercial terms updated. Active revenue rules were synchronized.');
    }

    public function status(
        Request $request,
        Publisher $publisher,
        PublisherContract $contract,
        ContractLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->belongsTo($publisher, $contract);
        $data = $request->validate([
            'status' => ['required', Rule::enum(ContractStatus::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $lifecycle->transition(
            $contract,
            ContractStatus::from($data['status']),
            $request->user(),
            $data['reason'] ?? null,
        );

        return back()->with('status', 'Commercial terms status updated.');
    }

    /**
     * Commercial terms are now data-only. These legacy endpoints intentionally
     * fail closed so stale bookmarks cannot reintroduce a document/signature flow.
     */
    public function upload(Publisher $publisher, PublisherContract $contract): Response
    {
        $this->belongsTo($publisher, $contract);
        abort(404);
    }

    public function download(Publisher $publisher, PublisherContract $contract): Response
    {
        $this->belongsTo($publisher, $contract);
        abort(404);
    }

    private function validated(Request $request, Publisher $publisher, ?PublisherContract $contract = null): array
    {
        return $request->validate([
            'contract_reference' => ['required', 'string', 'max:100', Rule::unique('publisher_contracts')->where('publisher_id', $publisher->id)->ignore($contract)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'auto_renews' => ['sometimes', 'boolean'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'],
            'payment_threshold' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_terms' => ['required', 'string', 'max:100'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function belongsTo(Publisher $publisher, PublisherContract $contract): void
    {
        abort_unless($contract->publisher_id === $publisher->id, 404);
    }
}
