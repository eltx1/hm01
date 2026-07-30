<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\PublisherStatement;
use App\Services\Reporting\PublisherStatementService;
use App\Services\Reporting\UnifiedReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function index(Request $request, UnifiedReportService $reports): View
    {
        $publisher = $request->user()->organization?->publisher;
        abort_unless($publisher, 404);

        return view('publisher.reporting.index', [
            'summary' => $reports->publisherSummary(
                $publisher,
                $request->date('from') ?: now()->startOfMonth(),
                $request->date('to') ?: now(),
            ),
            'publisher' => $publisher,
        ]);
    }

    public function statement(Request $request, PublisherStatement $publisherStatement): View
    {
        $this->authorizeStatement($request, $publisherStatement);
        $publisherStatement->load(['publisher', 'period', 'payments']);

        return view('publisher.reporting.statement', ['statement' => $publisherStatement]);
    }

    public function csv(Request $request, PublisherStatement $publisherStatement, PublisherStatementService $statements): StreamedResponse
    {
        $this->authorizeStatement($request, $publisherStatement);

        return $statements->csv($publisherStatement);
    }

    public function invoice(Request $request, PublisherStatement $publisherStatement, PublisherStatementService $statements): RedirectResponse
    {
        $this->authorizeStatement($request, $publisherStatement);
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:128'],
            'invoice' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);
        $statements->uploadInvoice($publisherStatement, $data['invoice'], $data['invoice_number'], $request->user());

        return back()->with('status', 'Publisher invoice uploaded for finance review.');
    }

    private function authorizeStatement(Request $request, PublisherStatement $statement): void
    {
        $publisherId = $request->user()->organization?->publisher?->id;
        abort_unless($publisherId && $statement->publisher_id === $publisherId, 404);
    }
}
