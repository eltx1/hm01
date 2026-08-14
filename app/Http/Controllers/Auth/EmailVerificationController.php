<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRecorder;
use App\Services\PublisherApplications\PublisherApplicationService;
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

    public function verify(EmailVerificationRequest $request, AuditRecorder $audit, PublisherApplicationService $applications): RedirectResponse
    {
        $wasVerified = $request->user()->hasVerifiedEmail();
        $request->fulfill();

        if (! $wasVerified) {
            $audit->record('auth.email.verified', $request->user()->organization_id, $request->user());
        }

        $application = $applications->emailVerified($request->user());

        return redirect()->route($application ? 'publisher-application.show' : 'dashboard');
    }

    public function send(Request $request): RedirectResponse
    {
        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}
