<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Recaptcha\RecaptchaObservationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RecaptchaObservationController extends Controller
{
    public function update(Request $request, Site $site, RecaptchaObservationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'site_key' => ['nullable', 'required_if:enabled,1', 'string', 'min:20', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'recaptcha_observation_secret' => ['nullable', 'string', 'max:2000'],
            'clear_secret' => ['sometimes', 'boolean'],
        ]);
        $settings->update($site, $request->user(), $data);
        return back()->with('operation_reference', $request->header('X-Request-ID'))->with('status', 'reCAPTCHA observation settings saved. Active sites publish through normal CDN delivery. Advertising and Cloudflare decisions are unchanged.');
    }
}
