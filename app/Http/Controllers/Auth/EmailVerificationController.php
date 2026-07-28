<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRecorder;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(): View
    {
        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $wasVerified = $request->user()->hasVerifiedEmail();
        $request->fulfill();

        if (! $wasVerified) {
            $audit->record('auth.email.verified', $request->user()->organization_id, $request->user());
        }

        return redirect()->route('dashboard');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
