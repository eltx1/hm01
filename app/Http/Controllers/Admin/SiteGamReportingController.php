<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\SiteGamReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SiteGamReportingController extends Controller
{
    public function store(Request $request, Site $site, SiteGamReportingService $reporting): RedirectResponse
    {
        $data = $request->validate(['gam_connection_id' => ['required', 'ulid'], 'ad_unit' => ['required', 'string', 'max:255']]);
        try {
            $binding = $reporting->bind($site, $data['gam_connection_id'], $data['ad_unit'], $request->user());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->withErrors(['gam_connection_id' => 'Could not verify the Google account and ad unit. Check the account reporting access and try again.']);
        }

        return redirect()->route('admin.sites.show', $site)->withFragment('reporting')
            ->with('status', 'GAM ad-unit reports connected from '.$binding->starts_on->toDateString().'. Synchronization starts automatically within five minutes.');
    }

    public function units(Request $request, Site $site, SiteGamReportingService $reporting, GamAdUnitReportClient $google): JsonResponse
    {
        $data = $request->validate(['gam_connection_id' => ['required', 'ulid'], 'q' => ['nullable', 'string', 'max:255']]);
        $connection = $reporting->availableConnections($site)->findOrFail($data['gam_connection_id']);
        try {
            $units = $google->units($connection, trim($data['q'] ?? ''), search: true);
        } catch (\Throwable) {
            return response()->json(['message' => 'Unit search is unavailable. You can still enter an exact name, code or ID.'], 503);
        }

        return response()->json(['units' => array_map(fn ($unit) => [
            'id' => (string) $unit['id'], 'name' => $unit['name'], 'code' => $unit['adUnitCode'],
        ], $units)])->header('Cache-Control', 'private, no-store');
    }
}
