<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Reporting\GamRevenueComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Session-only historical evidence. There is intentionally no apply endpoint. */
final class GamRevenueComparisonController extends Controller
{
    private const SESSION_KEY = 'private_gam_revenue_comparisons';

    public function index(Request $request, GamRevenueComparisonService $comparisons)
    {
        $this->authorizeReader($request);
        $selected = $request->filled('comparison')
            ? $this->entry($request, (string) $request->query('comparison'), $comparisons) : null;

        return response()->view('admin.reporting.gam-comparison', [
            'sites' => Site::query()->whereHas('currentGamReportBinding')->orderBy('primary_domain')->get(['id', 'primary_domain']),
            'comparisons' => $this->entries($request), 'selected' => $selected,
        ]);
    }

    public function start(Request $request, GamRevenueComparisonService $comparisons)
    {
        $this->authorizeReader($request);
        $data = $request->validate([
            'site_id' => ['required', 'ulid'], 'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $site = Site::query()->findOrFail($data['site_id']);
        $entries = $this->entries($request);
        $selection = hash('sha256', implode('|', [$request->user()->id, $site->id, $data['from'], $data['to']]));
        $existing = collect($entries)->firstWhere('selection', $selection);
        if ($existing) {
            $comparisons->assertEvidence($existing);
            return redirect()->route('admin.reporting.gam-comparison', ['comparison' => $existing['id']]);
        }
        if (count($entries) >= 3) {
            throw ValidationException::withMessages(['comparison' => 'Discard a previous preview before starting another.']);
        }
        try {
            $context = $comparisons->context($site, $data['from'], $data['to']);
            $entry = [
                'id' => (string) Str::uuid(), 'actor_id' => $request->user()->id, 'selection' => $selection,
                'expires_at' => now()->addMinutes(30)->timestamp, 'context' => $context,
                'snapshot' => $comparisons->snapshot($context), 'query_hash' => $comparisons->queryHash($context),
                'job' => ['status' => 'STARTING'],
            ];
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['comparison' => GamRevenueComparisonService::errorCode($error)]);
        }
        // Persist the uncertain attempt BEFORE Google is called. Session locking
        // serializes double-clicks; interrupted requests cannot resubmit a job.
        $entries[$entry['id']] = $entry;
        $this->store($request, $entries);
        try {
            $entry['job'] = $comparisons->start($entry);
        } catch (\Throwable $error) {
            $entry['job'] = ['status' => 'FAILED', 'error' => GamRevenueComparisonService::errorCode($error)];
        }
        $entries[$entry['id']] = $entry;
        $this->store($request, $entries);

        return redirect()->route('admin.reporting.gam-comparison', ['comparison' => $entry['id']]);
    }

    public function poll(Request $request, string $comparison, GamRevenueComparisonService $comparisons)
    {
        $this->authorizeReader($request);
        $entry = $this->entry($request, $comparison, $comparisons);
        if ($entry['job']['status'] === 'PENDING') {
            try {
                $entry['job'] = $comparisons->poll($entry);
            } catch (\Throwable $error) {
                $entry['job']['status'] = 'FAILED';
                $entry['job']['error'] = GamRevenueComparisonService::errorCode($error);
            }
            $entries = $this->entries($request);
            $entries[$comparison] = $entry;
            $this->store($request, $entries);
        }

        return redirect()->route('admin.reporting.gam-comparison', ['comparison' => $comparison]);
    }

    public function download(Request $request, string $comparison, GamRevenueComparisonService $comparisons)
    {
        $this->authorizeReader($request);
        $entry = $this->entry($request, $comparison, $comparisons);
        abort_unless($entry['job']['status'] === 'COMPLETED', 409);
        // No credentials, signed download URLs, raw CSV, unrelated sites or raw
        // financial models are exposed in this private review artifact.
        return response()->json([
            'context' => $entry['context'], 'query' => $comparisons->query($entry['context']),
            'query_hash' => $entry['query_hash'], 'snapshot_fingerprint' => $entry['snapshot']['fingerprint'],
            'completed_at' => $entry['job']['completed_at'], 'result' => $entry['job']['result'],
        ], options: JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="gam-historical-preview.json"');
    }

    public function discard(Request $request, string $comparison)
    {
        $this->authorizeReader($request);
        $entries = $this->entries($request);
        abort_unless(isset($entries[$comparison]), 404);
        unset($entries[$comparison]);
        $this->store($request, $entries);

        return redirect()->route('admin.reporting.gam-comparison');
    }

    private function entry(Request $request, string $id, GamRevenueComparisonService $comparisons): array
    {
        $entry = $this->entries($request)[$id] ?? null;
        abort_unless($entry, 404);
        $comparisons->assertEvidence($entry);

        return $entry;
    }

    private function entries(Request $request): array
    {
        $entries = array_filter((array) $request->session()->get(self::SESSION_KEY, []),
            fn ($entry) => is_array($entry) && ($entry['actor_id'] ?? null) === $request->user()->id
                && ($entry['expires_at'] ?? 0) > now()->timestamp);
        $request->session()->put(self::SESSION_KEY, $entries);

        return $entries;
    }

    private function store(Request $request, array $entries): void
    {
        $request->session()->put(self::SESSION_KEY, $entries);
        $request->session()->save();
    }

    private function authorizeReader(Request $request): void
    {
        abort_unless($request->user()?->isHorusAdministrator() && $request->user()->hasPermission('reporting.admin.view'), 403);
        abort_if(config('session.driver') === 'cookie'
            || (config('session.driver') === 'array' && ! app()->environment('testing')), 503,
            'Private previews require server-side sessions.');
    }
}
