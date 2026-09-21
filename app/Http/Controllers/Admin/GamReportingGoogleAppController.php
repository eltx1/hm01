<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Gam\GamReportingGoogleApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/** One-time platform provisioning, separate from website account authorization. */
class GamReportingGoogleAppController extends Controller
{
    public function show(GamReportingGoogleApp $google): Response
    {
        return response()->view('admin.gam.reporting-google-app', [
            'ready' => $google->ready(),
            'externallyConfigured' => $this->externallyConfigured(),
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, GamReportingGoogleApp $google): RedirectResponse
    {
        $request->validate(['google_app' => ['required', 'file', 'max:32']]);

        try {
            $status = Cache::lock('gam-reporting-google-app-setup', 30)->block(5, function () use ($request, $google): string {
                // Repeated submissions cannot replace an active platform client.
                if ($google->ready()) {
                    return 'Google connection setup is already complete. Choose a website to authorize an account.';
                }
                if ($this->externallyConfigured()) {
                    throw ValidationException::withMessages(['google_app' => 'Google connection setup is managed by server configuration. Contact the platform operator to correct it.']);
                }
                $json = json_decode(file_get_contents($request->file('google_app')->getRealPath()), true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($json)) {
                    throw new \RuntimeException;
                }
                $google->save($json, $request->user());

                return 'Google application saved securely. Choose a website and connect with Google to authorize its reports.';
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Never flash uploaded material, filenames, paths or parser errors.
            throw ValidationException::withMessages(['google_app' => 'The Google application could not be saved. Choose the original Web application JSON file and try again.']);
        }

        return redirect()->route('admin.gam.reporting.google-app.show')->with('status', $status)
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    private function externallyConfigured(): bool
    {
        $reference = config('gam.onboarding.oauth_app_reference');

        return is_string($reference) && $reference !== '';
    }
}
