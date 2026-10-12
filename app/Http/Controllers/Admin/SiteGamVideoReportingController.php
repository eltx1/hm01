<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Reporting\SiteGamVideoReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SiteGamVideoReportingController extends Controller
{
    public function store(Request $request, Site $site, SiteGamVideoReportingService $reporting): RedirectResponse
    {
        $data = $request->validate(['video_gam_connection_id' => ['required', 'ulid'], 'video_ad_unit' => ['required', 'string', 'max:255']]);
        try {
            $binding = $reporting->bind($site, $data['video_gam_connection_id'], $data['video_ad_unit'], $request->user());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) $errors[in_array($key, ['gam_connection_id', 'ad_unit'], true) ? 'video_'.$key : $key] = $messages;
            throw ValidationException::withMessages($errors);
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withInput()->withErrors(['video_gam_connection_id' => 'Could not verify the Video account and ad unit. Check reporting access and try again.']);
        }
        return redirect()->route('admin.sites.show', $site)->withFragment('video-reporting')
            ->with('status', 'Independent Video reports connected from '.$binding->starts_on->toDateString().'.');
    }

    public function backfill(Request $request, Site $site, \App\Services\Reporting\SiteGamVideoBackfillService $backfill): RedirectResponse
    {
        $data = $request->validate(['video_binding_id' => ['required', 'ulid'],
            'video_history_from' => ['required', 'date_format:Y-m-d'],
            'video_history_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:video_history_from'],
            'video_history_reason' => ['required', 'string', 'min:12', 'max:1000'],
            'confirm_video_history' => ['accepted']]);
        $binding = $backfill->create($site, $data['video_binding_id'], $data['video_history_from'], $data['video_history_to'], $data['video_history_reason'], $request->user());
        return redirect()->route('admin.sites.show', $site)->withFragment('video-reporting')
            ->with('status', 'Video history scheduled for '.$binding->starts_on->toDateString().' to '.$binding->ends_on->toDateString().'. Earnings appear only after verified Google import.');
    }

    public function destroy(Request $request, Site $site, SiteGamVideoReportingService $reporting): RedirectResponse
    {
        $reporting->disable($site, $request->user());
        return redirect()->route('admin.sites.show', $site)->withFragment('video-reporting')
            ->with('status', 'Video reporting disabled. Historical reports and earnings are preserved.');
    }
}
