<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\PublisherContract;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ContractController extends Controller
{
    public function index(): View
    {
        return view('publisher.contracts.index', ['contracts' => PublisherContract::query()->latest()->get()]);
    }

    public function show(PublisherContract $contract): View
    {
        return view('publisher.contracts.show', compact('contract'));
    }

    /**
     * Commercial terms are account data, not a publisher document workflow.
     * Keep this compatibility endpoint fail-closed for stale links.
     */
    public function download(PublisherContract $contract): Response
    {
        abort(404);
    }
}
