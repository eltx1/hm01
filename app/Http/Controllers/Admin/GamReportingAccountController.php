<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Gam\GamReportingGoogleApp;
use App\Services\Gam\GamReportingOnboarding;
use App\Services\Reporting\SiteGamReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

class GamReportingAccountController extends Controller
{
    public function show(Request $request, Site $site, GamReportingOnboarding $onboarding, GamReportingGoogleApp $googleApp): Response
    {
        $pending = null;
        $flow = (string) $request->query('flow', '');
        if ($flow !== '') {
            try {
                $pending = $onboarding->pending($request, $site, $flow);
            } catch (ValidationException $exception) {
                $request->session()->flash('error', $exception->getMessage());
            }
        }

        return response()->view('admin.gam.reporting-connect', [
            'site' => $site, 'googleReady' => $googleApp->ready(), 'adUnit' => $pending['ad_unit'] ?? '',
            'flow' => $flow, 'networks' => $pending['networks'] ?? [], 'accountEmail' => $pending['email'] ?? null,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function setup(Request $request, Site $site, GamReportingOnboarding $onboarding): RedirectResponse
    {
        $onboarding->saveApp($this->uploadedJson($request, 'google_app'), $request->user());

        return $this->start($request, $site, $onboarding);
    }

    public function start(Request $request, Site $site, GamReportingOnboarding $onboarding): RedirectResponse
    {
        return redirect()->away($onboarding->start($request, $site))->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function callback(Request $request, GamReportingOnboarding $onboarding): RedirectResponse
    {
        $site = null;
        try {
            $state = $onboarding->consumeState($request);
            $site = Site::withoutGlobalScopes()->findOrFail($state['site_id']);
            $flow = $onboarding->finishGoogle($request, $state, $site);

            return $this->discovered($request, $site, $flow, $onboarding);
        } catch (ValidationException $exception) {
            return ($site ? redirect()->route('admin.sites.reporting.accounts.show', $site) : redirect()->route('admin.sites.index'))
                ->withErrors($exception->errors())->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (Throwable) {
            return redirect()->route('admin.sites.index')->withErrors(['gam_account' => 'The Google connection could not be completed. Start again from the website.']);
        }
    }

    public function upload(Request $request, Site $site, GamReportingOnboarding $onboarding): RedirectResponse
    {
        $flow = $onboarding->serviceAccount($request, $site, $this->uploadedJson($request, 'service_account'));

        return $this->discovered($request, $site, $flow, $onboarding);
    }

    public function connect(Request $request, Site $site, GamReportingOnboarding $onboarding): RedirectResponse
    {
        $data = $request->validate(['flow' => ['required', 'ulid'], 'networks' => ['required', 'array', 'min:1', 'max:25'], 'networks.*' => ['required', 'string', 'regex:/^\d{1,64}$/D']]);
        $pending = $onboarding->pending($request, $site, $data['flow']);
        $ids = $onboarding->connect($request, $site, $data['flow'], $data['networks']);

        return $this->connected($request, $site, $ids, $pending['ad_unit'] ?? '');
    }

    private function discovered(Request $request, Site $site, string $flow, GamReportingOnboarding $onboarding): RedirectResponse
    {
        $pending = $onboarding->pending($request, $site, $flow);
        if (count($pending['networks']) === 1) {
            return $this->connected($request, $site, $onboarding->connect($request, $site, $flow, array_map('strval', array_keys($pending['networks']))), $pending['ad_unit'] ?? '');
        }

        return redirect()->route('admin.sites.reporting.accounts.show', ['site' => $site, 'flow' => $flow])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function connected(Request $request, Site $site, array $ids, string $adUnit = ''): RedirectResponse
    {
        $response = redirect()->route('admin.sites.show', $site)->withFragment('reporting')
            ->with('reporting_gam_connection_id', $ids[0])
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
        if ($adUnit !== '') {
            try {
                $binding = app(SiteGamReportingService::class)->bind($site, $ids[0], $adUnit, $request->user());

                return $response->with('status', 'Reports connected to '.$binding->ad_unit_name.'. Synchronization starts automatically within five minutes.');
            } catch (ValidationException $exception) {
                $response->withErrors($exception->errors());
            } catch (Throwable) {
                // Keep the authorized account, and never log provider secrets.
                $response->withErrors(['ad_unit' => 'The account is connected, but the ad unit could not be verified. Try selecting the unit below.']);
            }

            return $response->withInput(['gam_connection_id' => $ids[0], 'ad_unit' => $adUnit])
                ->with('status', 'Your Google account is saved. Choose or correct the ad unit below; you do not need to sign in again.');
        }

        return $response->with('status', count($ids).' Ad Manager account(s) connected for reports. Choose the website’s ad unit below to finish.');
    }

    private function uploadedJson(Request $request, string $field): array
    {
        $request->validate([$field => ['required', 'file', 'max:32']]);
        try {
            $json = json_decode(file_get_contents($request->file($field)->getRealPath()), true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($json)) {
                throw new \RuntimeException;
            }

            return $json;
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Choose the original JSON file downloaded from Google Cloud.']);
        }
    }
}
