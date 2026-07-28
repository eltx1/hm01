<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\PublisherContract;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function download(PublisherContract $contract): StreamedResponse|Response
    {
        abort_unless($contract->contract_file_path, 404);
        try {
            return Storage::disk('local')->download($contract->contract_file_path, $contract->contract_file_name);
        } catch (FileNotFoundException) {
            abort(404);
        }
    }
}
