<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportPeriodRequest;
use App\Services\Reporting\PublisherPerformanceService;
use App\Http\Requests\SavePublisherPaymentProfileRequest;
use App\Models\Publisher;
use App\Models\PublisherStatement;
use App\Models\Site;
use App\Services\Reporting\AdFormatBreakdownCsv;
use App\Services\Reporting\AdFormatBreakdownService;
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
    public function index(ReportPeriodRequest $request): View|StreamedResponse
    {
        $publisher = $this->publisher($request);
        $performance = app(PublisherPerformanceService::class)->summary(
            $publisher, $request->validated('from'), $request->validated('to'),
        );
        if ($request->validated('export') === 'video_csv') {
            return app(\App\Services\Reporting\VideoReportCsv::class)->download($performance['video'], true);
        }
        if ($request->validated('export') === 'csv') {
            return app(\App\Services\Reporting\PerformanceReportCsv::class)->download($performance['days'], $request->selectedMetrics($performance), $performance['currency'], true);
        }

        return view('publisher.reporting.index', [
            'performance' => $performance,
            'reportMetrics' => $request->selectedMetrics($performance),
            ...$this->comparison($publisher, $performance),
        ]);
    }

    /** Same publisher-safe earnings, broken down by Google inventory format. */
    public function adFormats(ReportPeriodRequest $request, AdFormatBreakdownService $breakdowns): View|StreamedResponse
    {
        $publisher = $this->publisher($request);
        $sites = Site::withoutGlobalScopes()->where('organization_id', $publisher->organization_id)
            ->where('publisher_id', $publisher->id)->orderBy('display_name')->orderBy('id')->get();
        $siteId = $request->validate(['site' => ['nullable', 'string', 'max:64']])['site'] ?? null;
        $selected = $siteId ? $sites->where('id', $siteId)->values() : $sites;
        abort_if($siteId && $selected->isEmpty(), 404);
        $dimension = $this->dimension($request);
        $breakdown = $breakdowns->summary($selected, $request->validated('from'), $request->validated('to'), publisherView: true,
            publisher: $siteId ? null : $publisher, dimension: $dimension);
        $metrics = array_values(array_diff($request->selectedMetrics(['has_site_ad_exchange' => true]), ['unfilled_impressions']));
        if ($request->validated('export') === 'csv') {
            return app(AdFormatBreakdownCsv::class)->download($breakdown, $metrics, true);
        }

        $period = \App\Support\PeriodComparison::previous($breakdown['from'], $breakdown['to']);

        return view('publisher.reporting.ad-formats', [
            'previousPeriod' => $period,
            'previousBreakdown' => $breakdowns->summary($selected, $period['from'], $period['to'], publisherView: true, publisher: $siteId ? null : $publisher, dimension: $dimension),
            'breakdown' => $breakdown, 'reportMetrics' => $metrics,
            'sites' => $sites, 'selectedSite' => $siteId,
        ]);
    }

    public function overview(ReportPeriodRequest $request, PublisherFinanceService $finance): View|StreamedResponse
    {
        $publisher = $this->publisher($request);
        $performance = app(PublisherPerformanceService::class)->summary(
            $publisher, $request->validated('from'), $request->validated('to'),
        );
        if ($request->validated('export') === 'video_csv') {
            return app(\App\Services\Reporting\VideoReportCsv::class)->download($performance['video'], true);
        }
        if ($request->validated('export') === 'csv') {
            return app(\App\Services\Reporting\PerformanceReportCsv::class)->download($performance['days'], $request->selectedMetrics($performance), $performance['currency'], true);
        }

        return view('publisher.finance.overview', [
            ...$finance->overview($publisher),
            'performance' => $performance,
            'reportMetrics' => $request->selectedMetrics($performance),
            ...$this->comparison($publisher, $performance),
        ]);
    }

    /** Same metrics for the equal-length period immediately before the selection. */
    private function comparison(Publisher $publisher, array $performance): array
    {
        $period = \App\Support\PeriodComparison::previous($performance['from'], $performance['to']);
        $previous = app(PublisherPerformanceService::class)->summary($publisher, $period['from'], $period['to']);

        return ['previousPeriod' => $period, 'previous' => $previous['available'] ? $previous : null];
    }

    public function statements(Request $request, PublisherFinanceService $finance): View
    {
        $publisher = $this->publisher($request);

        return view('publisher.finance.statements', [
            'publisher' => $publisher,
            'statements' => $finance->statements($publisher),
        ]);
    }

    public function statement(
        Request $request,
        PublisherStatement $publisherStatement,
        PublisherFinanceService $finance,
    ): View {
        $this->authorizeStatement($request, $publisherStatement);

        return view('publisher.finance.statement', [
            'statement' => $finance->statement($publisherStatement),
        ]);
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

    /** Breakdown dimension selected on the page: ad format (default), country or device. */
    private function dimension(\Illuminate\Http\Request $request): string
    {
        return ['country' => 'COUNTRY', 'device' => 'DEVICE'][$request->query('by')] ?? 'FORMAT';
    }
}
