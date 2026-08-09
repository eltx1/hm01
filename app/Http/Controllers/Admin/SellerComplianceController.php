<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SellerType;
use App\Enums\SupplyChainReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\SellerDeclaration;
use App\Models\Site;
use App\Services\Compliance\SellerDeclarationManager;
use App\Services\Compliance\SupplyChainComplianceService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class SellerComplianceController extends Controller
{
    public function index(SupplyChainComplianceService $compliance): View
    {
        $declarations = SellerDeclaration::withoutGlobalScope('organization')
            ->with(['publisher', 'site', 'reviewer'])->orderBy('seller_id')->orderBy('site_id')->paginate(25);
        $network = $compliance->networkOverview();
        $summaries = $declarations->getCollection()->mapWithKeys(
            fn (SellerDeclaration $declaration): array => [
                $declaration->id => $compliance->declarationOverview($declaration, $network['network']),
            ],
        );

        return view('admin.compliance.sellers.index', [
            'declarations' => $declarations,
            'summaries' => $summaries,
            'network' => $network,
            'publishers' => Publisher::withoutGlobalScope('organization')->with('sites')->orderBy('display_name')->get(),
            'sites' => Site::withoutGlobalScope('organization')->with('publisher')->orderBy('primary_domain')->get(),
        ]);
    }

    public function show(SellerDeclaration $seller, SupplyChainComplianceService $compliance): View
    {
        $seller->load(['publisher.sites', 'site', 'reviewer']);

        return view('admin.compliance.sellers.show', [
            'seller' => $seller,
            'summary' => $compliance->declarationOverview($seller),
            'sites' => Site::withoutGlobalScope('organization')
                ->where('publisher_id', $seller->publisher_id)->orderBy('primary_domain')->get(),
            'auditEvents' => AuditLog::query()
                ->where('auditable_type', $seller->getMorphClass())
                ->where('auditable_id', $seller->id)
                ->where('event', 'like', 'supply_chain.seller.%')
                ->latest()->limit(100)->get(),
        ]);
    }

    public function artifact(SupplyChainArtifactBuilder $artifacts): Response
    {
        return response($artifacts->sellersJson(), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="sellers.json"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function store(Request $request, SellerDeclarationManager $manager): RedirectResponse
    {
        $seller = $manager->create($this->sellerData($request, true), $request->user());

        return redirect()->route('admin.compliance.sellers.show', $seller)
            ->with('status', 'Seller declaration created disabled. Verify the identity before activation.');
    }

    public function update(Request $request, SellerDeclaration $seller, SellerDeclarationManager $manager): RedirectResponse
    {
        $updated = $manager->update($seller, $this->sellerData($request), $request->user());

        return redirect()->route('admin.compliance.sellers.show', $updated)
            ->with('status', 'Seller declaration updated, disabled, and returned to review required.');
    }

    public function review(Request $request, SellerDeclaration $seller, SellerDeclarationManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'review_status' => ['required', Rule::enum(SupplyChainReviewStatus::class)],
        ]);
        $manager->review($seller, SupplyChainReviewStatus::from($data['review_status']), $request->user());

        return back()->with('status', 'Seller identity review recorded.');
    }

    public function activate(Request $request, SellerDeclaration $seller, SellerDeclarationManager $manager): RedirectResponse
    {
        $manager->activate($seller, $request->user());

        return back()->with('status', 'Seller declaration activated and static publication queued.');
    }

    public function deactivate(Request $request, SellerDeclaration $seller, SellerDeclarationManager $manager): RedirectResponse
    {
        $manager->deactivate($seller, $request->user());

        return back()->with('status', 'Seller declaration deactivated and static publication queued.');
    }

    /** @return array<string, mixed> */
    private function sellerData(Request $request, bool $creating = false): array
    {
        $rules = [
            'site_id' => ['nullable', 'ulid', 'exists:sites,id'],
            'seller_id' => ['required', 'string', 'max:64', 'regex:/^[^\s,\x00-\x1F\x7F]+$/u'],
            'seller_type' => ['required', Rule::enum(SellerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:253'],
            'is_confidential' => ['sometimes', 'boolean'],
        ];
        if ($creating) {
            $rules['publisher_id'] = ['required', 'ulid', 'exists:publishers,id'];
        }
        $data = $request->validate($rules);
        $data['is_confidential'] = $request->boolean('is_confidential');

        return $data;
    }
}
