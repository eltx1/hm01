<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GamRevenueCorrection;
use App\Models\Site;
use App\Models\User;
use App\Services\Reporting\GamRevenueCorrectionService;
use Illuminate\Http\Request;

final class GamRevenueCorrectionController extends Controller
{
    public function index(Request $request)
    {
        $actor = $this->authorizeReviewer($request);

        return $this->review($actor);
    }

    public function start(Request $request, GamRevenueCorrectionService $corrections)
    {
        $actor = $this->authorizeReviewer($request);
        $data = $request->validate([
            'site_id' => ['required', 'ulid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $site = Site::query()->findOrFail($data['site_id']);
        $candidate = $corrections->start($site, $data['from'], $data['to'], $actor);

        return redirect()->route('admin.reporting.gam-corrections.show', $candidate->id);
    }

    public function show(Request $request, string $correction)
    {
        $actor = $this->authorizeReviewer($request);

        return $this->review($actor, $this->candidate($actor, $correction));
    }

    public function poll(Request $request, string $correction, GamRevenueCorrectionService $corrections)
    {
        $actor = $this->authorizeReviewer($request);
        $candidate = $corrections->poll($this->candidate($actor, $correction), $actor);

        return redirect()->route('admin.reporting.gam-corrections.show', $candidate->id);
    }

    public function apply(Request $request, string $correction, GamRevenueCorrectionService $corrections)
    {
        $actor = $this->authorizeReviewer($request);
        $candidate = $this->candidate($actor, $correction);
        $data = $request->validate([
            'digest' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'confirm_review' => ['required', 'accepted'],
            'reason' => ['required', 'string', 'min:12', 'max:2000'],
        ]);
        // Amounts, rules and scope come only from immutable server-side evidence.
        $candidate = $corrections->apply($candidate, $data['digest'], $data['reason'], $actor);

        return redirect()->route('admin.reporting.gam-corrections.show', $candidate->id);
    }

    public function replace(Request $request, string $correction, GamRevenueCorrectionService $corrections)
    {
        $actor = $this->authorizeReviewer($request);
        $candidate = $corrections->replace($this->candidate($actor, $correction), $actor);

        return redirect()->route('admin.reporting.gam-corrections.show', $candidate->id);
    }

    private function candidate(User $actor, string $id): GamRevenueCorrection
    {
        return GamRevenueCorrection::query()->where('actor_id', $actor->id)->findOrFail($id);
    }

    private function review(User $actor, ?GamRevenueCorrection $selected = null)
    {
        return response()->view('admin.reporting.gam-corrections', [
            'sites' => Site::query()->whereHas('currentGamReportBinding')->orderBy('primary_domain')->get(['id', 'primary_domain']),
            'corrections' => GamRevenueCorrection::query()->where('actor_id', $actor->id)->latest()->limit(10)->get(),
            'selected' => $selected,
        ]);
    }

    private function authorizeReviewer(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor?->isHorusAdministrator()
            && $actor->hasPermission('reporting.admin.view')
            && $actor->hasPermission('reporting.import')
            && $actor->hasPermission('finance.adjustments.approve'), 403);

        return $actor;
    }
}
