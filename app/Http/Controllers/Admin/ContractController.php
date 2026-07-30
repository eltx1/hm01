<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Publisher;
use App\Models\PublisherContract;
use App\Services\Audit\AuditRecorder;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Uploads\SecureUploadService;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    public function index(Publisher $publisher): View
    {
        return view('admin.contracts.index', ['publisher' => $publisher, 'contracts' => $publisher->contracts()->latest()->get()]);
    }

    public function create(Publisher $publisher): View
    {
        return view('admin.contracts.form', ['publisher' => $publisher, 'contract' => new PublisherContract]);
    }

    public function store(Request $request, Publisher $publisher, AuditRecorder $audit): RedirectResponse
    {
        $contract = PublisherContract::create(array_merge($this->validated($request, $publisher), ['organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id, 'created_by' => $request->user()->id, 'status' => ContractStatus::Draft]));
        $audit->record('publisher_contract.created', $publisher->organization_id, $request->user(), $contract, newValues: $contract->only(['contract_reference', 'revenue_share_percent', 'payment_threshold', 'status']));

        return redirect()->route('admin.publishers.contracts.edit', [$publisher, $contract])->with('status', 'Contract created.');
    }

    public function edit(Publisher $publisher, PublisherContract $contract): View
    {
        $this->belongsTo($publisher, $contract);

        return view('admin.contracts.form', compact('publisher', 'contract'));
    }

    public function update(Request $request, Publisher $publisher, PublisherContract $contract, AuditRecorder $audit): RedirectResponse
    {
        $this->belongsTo($publisher, $contract);
        $before = $contract->only(['contract_reference', 'starts_at', 'ends_at', 'auto_renews', 'revenue_share_percent', 'payment_threshold', 'payment_terms', 'internal_notes']);
        $contract->update($this->validated($request, $publisher, $contract));
        $audit->record('publisher_contract.updated', $publisher->organization_id, $request->user(), $contract, $before, $contract->only(array_keys($before)));

        return back()->with('status', 'Contract updated.');
    }

    public function status(Request $request, Publisher $publisher, PublisherContract $contract, ContractLifecycleService $lifecycle): RedirectResponse
    {
        $this->belongsTo($publisher, $contract);
        $data = $request->validate(['status' => ['required', Rule::enum(ContractStatus::class)], 'reason' => ['nullable', 'string', 'max:2000']]);
        $lifecycle->transition($contract, ContractStatus::from($data['status']), $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Contract status updated.');
    }

    public function upload(Request $request, Publisher $publisher, PublisherContract $contract, AuditRecorder $audit, SecureUploadService $uploads): RedirectResponse
    {
        $this->belongsTo($publisher, $contract);
        $data = $request->validate(['contract_file' => ['required', 'file']]);
        $file = $data['contract_file'];
        $stored = $uploads->store($file, 'contracts/'.$publisher->id.'/'.$contract->id, [
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ], (int) config('security.uploads.contract_max_bytes'));
        $oldPath = $contract->contract_file_path;
        $path = $stored['path'];
        $contract->update(['contract_file_path' => $path, 'contract_file_name' => basename($file->getClientOriginalName()), 'contract_file_mime' => $stored['mime']]);
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
        $audit->record('publisher_contract.file_uploaded', $publisher->organization_id, $request->user(), $contract, metadata: ['file_name' => $contract->contract_file_name, 'mime' => $contract->contract_file_mime, 'checksum' => $stored['checksum']]);

        return back()->with('status', 'Contract document uploaded to private storage.');
    }

    public function download(Publisher $publisher, PublisherContract $contract): StreamedResponse|Response
    {
        $this->belongsTo($publisher, $contract);
        abort_unless($contract->contract_file_path, 404);
        try {
            return Storage::disk('local')->download($contract->contract_file_path, $contract->contract_file_name);
        } catch (FileNotFoundException) {
            abort(404);
        }
    }

    private function validated(Request $request, Publisher $publisher, ?PublisherContract $contract = null): array
    {
        return $request->validate([
            'contract_reference' => ['required', 'string', 'max:100', Rule::unique('publisher_contracts')->where('publisher_id', $publisher->id)->ignore($contract)],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'auto_renews' => ['sometimes', 'boolean'],
            'revenue_share_percent' => ['required', 'numeric', 'between:0,100'], 'payment_threshold' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'], 'payment_terms' => ['required', 'string', 'max:100'], 'internal_notes' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function belongsTo(Publisher $publisher, PublisherContract $contract): void
    {
        abort_unless($contract->publisher_id === $publisher->id, 404);
    }
}
