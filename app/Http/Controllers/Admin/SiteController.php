<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConfigEnvironment;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Enums\VerificationMethod;
use App\Http\Controllers\Controller;
use App\Models\ServingModeChange;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNote;
use App\Models\SiteReview;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Sites\DomainVerificationService;
use App\Services\Sites\SiteLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(): View
    {
        return view('admin.sites.index', ['sites' => Site::withoutGlobalScopes()->with('publisher')->latest()->paginate(25)]);
    }

    public function show(Site $site): View
    {
        return view('publisher.sites.show', ['site' => $site->load(['publisher', 'domains.verifications', 'reviews', 'notes', 'statusHistory', 'servingSettings', 'servingModeChanges', 'siteConfig']), 'internal' => true]);
    }

    public function approve(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['publisher_message' => ['nullable', 'string', 'max:5000'], 'internal_reason' => ['nullable', 'string', 'max:5000']]);
        $lifecycle->transition($site, SiteStatus::Approved, $request->user(), $data['internal_reason'] ?? 'Approved by Horus Media.');
        $this->review($site, $request, 'APPROVED', $data);

        return back()->with('status', 'Website approved. HORUS_GAM remains available and selected unless an administrator changes it.');
    }

    public function reject(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['publisher_message' => ['required', 'string', 'max:5000'], 'internal_reason' => ['required', 'string', 'max:5000']]);
        $lifecycle->transition($site, SiteStatus::Rejected, $request->user(), $data['internal_reason']);
        $this->review($site, $request, 'REJECTED', $data);

        return back()->with('status', 'Website rejected with a publisher-visible explanation.');
    }

    public function activate(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $lifecycle->transition($site, SiteStatus::Active, $request->user(), $data['reason'] ?? 'Activated by Horus Media.');

        return back()->with('status', 'Website activated.');
    }

    public function suspend(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        abort_unless(in_array($site->status, [SiteStatus::Approved, SiteStatus::Active], true), 422, 'Only approved or active websites can be operationally suspended.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $lifecycle->transition($site, SiteStatus::Suspended, $request->user(), $data['reason']);

        return back()->with('status', 'Website suspended.');
    }

    public function reactivate(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $suspension = $site->statusHistory()->where('new_status', SiteStatus::Suspended)->latest('created_at')->first();
        $target = $suspension?->previous_status && $suspension->previous_status !== SiteStatus::Suspended ? $suspension->previous_status : SiteStatus::Active;
        $lifecycle->transition($site, $target, $request->user(), $data['reason']);

        return back()->with('status', 'Website restored to its pre-suspension status. Serving mode was not changed automatically.');
    }

    public function archive(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $lifecycle->transition($site, SiteStatus::Archived, $request->user(), $data['reason']);

        return back()->with('status', 'Website archived. Its history and permanent key are retained.');
    }

    public function servingMode(Request $request, Site $site, SiteLifecycleService $lifecycle, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate(['serving_mode' => ['required', Rule::enum(ServingMode::class)], 'reason' => ['required', 'string', 'max:2000'], 'rollback_reference_id' => ['nullable', 'ulid']]);
        $rollback = isset($data['rollback_reference_id']) ? ServingModeChange::withoutGlobalScopes()->where('site_id', $site->id)->findOrFail($data['rollback_reference_id']) : null;
        $lifecycle->changeServingMode($site, ServingMode::from($data['serving_mode']), $request->user(), $data['reason'], $rollback);
        $version = $publisher->publish($site->refresh(), ConfigEnvironment::Production, $request->user());

        return back()->with('status', 'Serving mode changed and production configuration v'.$version->version.' published. Publisher installation code remains unchanged.');
    }

    public function revenueShare(Request $request, Site $site, SiteLifecycleService $lifecycle): RedirectResponse
    {
        $data = $request->validate(['revenue_share_percent' => ['required', 'numeric', 'between:0,100'], 'reason' => ['required', 'string', 'max:2000']]);
        $lifecycle->changeRevenueShare($site, (string) $data['revenue_share_percent'], $request->user(), $data['reason']);

        return back()->with('status', 'Website revenue share updated.');
    }

    public function note(Request $request, Site $site, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:10000']]);
        $note = SiteNote::create(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'author_id' => $request->user()->id, 'note' => $data['note']]);
        $audit->record('site.internal_note.added', $site->organization_id, $request->user(), $note, newValues: ['site_id' => $site->id]);

        return back()->with('status', 'Internal note added.');
    }

    public function manualVerify(Request $request, Site $site, SiteDomain $domain, DomainVerificationService $service, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($domain->site_id === $site->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $verification = $service->verify($domain, VerificationMethod::Manual, $request->user());
        $audit->record('site.domain.manually_verified', $site->organization_id, $request->user(), $domain, newValues: ['status' => $verification->status], metadata: ['reason' => $data['reason']]);

        return back()->with('status', 'Domain manually verified.');
    }

    public function emergencyPause(Request $request, Site $site, SiteLifecycleService $lifecycle, SiteConfigPublisher $publisher): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $lifecycle->emergencyPause($site, $request->user(), $data['reason']);
        $version = $publisher->pauseImmediately($site->refresh(), $request->user());

        return back()->with('status', 'Emergency pause applied and static production configuration v'.$version->version.' published.');
    }

    private function review(Site $site, Request $request, string $decision, array $data): void
    {
        $review = $site->reviews()->where('decision', 'PENDING')->latest()->first() ?? new SiteReview(['organization_id' => $site->organization_id, 'site_id' => $site->id, 'submitted_at' => $site->submitted_at]);
        $review->fill(['reviewer_id' => $request->user()->id, 'decision' => $decision, 'publisher_message' => $data['publisher_message'] ?? null, 'internal_reason' => $data['internal_reason'] ?? null, 'reviewed_at' => now()])->save();
    }
}
