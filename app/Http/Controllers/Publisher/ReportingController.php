<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePublisherPaymentProfileRequest;
use App\Models\Publisher;
use App\Models\PublisherStatement;
use App\Services\Reporting\PublisherFinanceService;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Reporting\PublisherStatementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function index(Request $request, PublisherFinanceService $finance): View
    {
        return view('publisher.finance.overview', $finance->overview($this->publisher($request)));
    }

    public function overview(Request $request, PublisherFinanceService $finance): View
    {
        return view('publisher.finance.overview', $finance->overview($this->publisher($request)));
    }

    public function statements(Request $request, PublisherFinanceService $finance): View
    {
        $publisher = $this->publisher($request);

        return view('publisher.finance.statements', [
            'publisher' => $publisher,
            'statements' => $finance->statements($publisher),
        ]);
    }

    public function statement(Request $request, PublisherStatement $publisherStatement): View
    {
        $this->authorizeStatement($request, $publisherStatement);
        $publisherStatement->load(['publisher', 'period', 'payments']);

        return view('publisher.finance.statement', ['statement' => $publisherStatement]);
    }

    public function csv(
        Request $request,
        PublisherStatement $publisherStatement,
        PublisherStatementService $statements,
    ): StreamedResponse {
        $this->authorizeStatement($request, $publisherStatement);

        return $statements->csv($publisherStatement, publisherSafe: true);
    }

    public function invoice(
        Request $request,
        PublisherStatement $publisherStatement,
        PublisherStatementService $statements,
    ): RedirectResponse {
        $this->authorizeStatement($request, $publisherStatement);
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:128'],
            'invoice' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg'],
        ]);
        $statements->uploadInvoice($publisherStatement, $data['invoice'], $data['invoice_number'], $request->user());

        return back()->with('status', 'Publisher invoice uploaded securely for Finance review.');
    }

    public function invoiceDownload(Request $request, PublisherStatement $publisherStatement): StreamedResponse
    {
        $this->authorizeStatement($request, $publisherStatement);
        abort_unless($publisherStatement->publisher_invoice_path, 404);
        abort_unless(Storage::disk('local')->exists($publisherStatement->publisher_invoice_path), 404);

        $extension = pathinfo($publisherStatement->publisher_invoice_path, PATHINFO_EXTENSION) ?: 'bin';

        return Storage::disk('local')->download(
            $publisherStatement->publisher_invoice_path,
            $publisherStatement->statement_number.'-invoice.'.$extension,
            ['Cache-Control' => 'private, no-store'],
        );
    }

    public function paymentMethod(Request $request): View
    {
        $publisher = $this->publisher($request);

        return view('publisher.finance.payment-method', [
            'publisher' => $publisher,
            'profile' => $publisher->paymentProfile,
        ]);
    }

    public function updatePaymentMethod(
        SavePublisherPaymentProfileRequest $request,
        PublisherPaymentProfileService $profiles,
    ): RedirectResponse {
        $profiles->save($this->publisher($request), $request->validated(), $request->user());

        return back()->with('status', 'Payment method saved. Sensitive values remain encrypted and will stay masked.');
    }

    public function payouts(Request $request, PublisherFinanceService $finance): View
    {
        $publisher = $this->publisher($request);

        return view('publisher.finance.payouts', [
            'publisher' => $publisher,
            'payments' => $finance->payments($publisher),
        ]);
    }

    private function authorizeStatement(Request $request, PublisherStatement $statement): void
    {
        $publisher = $this->publisher($request);
        abort_unless(
            $statement->publisher_id === $publisher->id
            && $statement->organization_id === $publisher->organization_id,
            404,
        );
    }

    private function publisher(Request $request): Publisher
    {
        return Publisher::withoutGlobalScopes()
            ->where('organization_id', $request->user()->organization_id)
            ->firstOrFail();
    }
}
