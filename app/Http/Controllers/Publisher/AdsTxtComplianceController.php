<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Compliance\AdsTxtVerifier;
use App\Services\Compliance\SupplyChainComplianceService;
use App\Services\Compliance\SupplyChainControlCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class AdsTxtComplianceController extends Controller
{
    public function index(
        AdsTxtComplianceService $compliance,
        SupplyChainComplianceService $supplyChain,
        SupplyChainControlCenterService $controlCenter,
    ): View {
        $sites = Site::query()->with('domains')->orderBy('primary_domain')->get();
        $summaries = $sites->mapWithKeys(function (Site $site) use ($compliance): array {
            $summary = $compliance->summary($site);
            $summary['canonical']['records'] = collect($summary['canonical']['records'])
                ->map(fn (array $record): array => ['canonical' => $record['canonical']])->all();

            return [$site->id => $summary];
        });
        $deploymentStates = $sites->mapWithKeys(
            fn (Site $site): array => [$site->id => $controlCenter->publisherSite($site)],
        );

        $publisher = request()->user()->organization->publisher;
        $sellerIdentities = $publisher ? $supplyChain->publisherOverview($publisher) : [];

        return view('publisher.compliance.ads-txt', compact('sites', 'summaries', 'deploymentStates', 'sellerIdentities'));
    }

    public function download(Site $site, AdsTxtComplianceService $compliance): Response
    {
        $content = $compliance->canonical($site)['content'];
        $name = preg_replace('/[^a-z0-9.-]/', '-', strtolower($site->primary_domain)).'-ads.txt';

        return response($content, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function verify(Request $request, Site $site, AdsTxtVerifier $verifier): RedirectResponse
    {
        $check = $verifier->verify($site, 'PUBLISHER', $request->user());

        return back()->with('status', 'Ads.txt check completed: '.$check->status.'.');
    }
}
