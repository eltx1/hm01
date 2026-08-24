<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PublisherApplication;
use App\Services\Audit\AuditRecorder;
use App\Services\PublisherApplications\PublisherApplicantEmailService;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        if (! $this->verificationRequired()) {
            return $this->verificationBypassDestination($request);
        }

        return view('auth.verify-email');
    }

    public function verify(EmailVerificationRequest $request, AuditRecorder $audit, PublisherApplicationService $applications): RedirectResponse
    {
        if (! $this->verificationRequired()) {
            return $this->verificationBypassDestination($request);
        }

        $wasVerified = $request->user()->hasVerifiedEmail();
        $request->fulfill();

        if (! $wasVerified) {
            $audit->record('auth.email.verified', $request->user()->organization_id, $request->user());
        }

        $applications->emailVerified($request->user());

        return redirect()->route($request->user()->isActive() ? 'dashboard' : 'publisher-application.show');
    }

    public function send(Request $request, PublisherApplicantEmailService $emails): RedirectResponse
    {
        if (! $this->verificationRequired()) {
            return $this->verificationBypassDestination($request);
        }

        $emails->sendVerification($request->user());

        return back()->with('status', 'verification-link-sent');
    }

    private function verificationRequired(): bool
    {
        return (bool) config('security.authentication.email_verification_required', true);
    }

    private function verificationBypassDestination(Request $request): RedirectResponse
    {
        if ($request->user()->isActive()) {
            return redirect()->route('dashboard');
        }

        $hasPublisherApplication = PublisherApplication::withoutGlobalScopes()->where('applicant_user_id', $request->user()->id)->exists();

        return redirect()->route($hasPublisherApplication ? 'publisher-application.show' : 'dashboard');
    }
}
