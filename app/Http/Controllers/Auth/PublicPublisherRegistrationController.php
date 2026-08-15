<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PublisherApplications\PublisherApplicantEmailService;
use App\Services\PublisherApplications\PublisherApplicationService;
use App\Services\PublisherApplications\TurnstileVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PublicPublisherRegistrationController extends Controller
{
    public function create(): View
    {
        abort_unless(config('publisher-applications.public_registration_enabled'), 404);

        return view('auth.register-publisher');
    }

    public function store(
        Request $request,
        PublisherApplicationService $applications,
        TurnstileVerifier $turnstile,
        PublisherApplicantEmailService $emails,
    ): RedirectResponse {
        abort_unless(config('publisher-applications.public_registration_enabled'), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
            'publisher_name' => ['required', 'string', 'max:255'],
            'primary_domain' => ['required', 'string', 'max:500'],
            '_company_website' => ['nullable', 'string', 'max:0'],
            'cf-turnstile-response' => [config('publisher-applications.turnstile.enabled') ? 'required' : 'nullable', 'string', 'max:2048'],
        ]);
        $turnstile->verify($data['cf-turnstile-response'] ?? null);

        try {
            $application = $applications->register($data);
        } catch (ValidationException $exception) {
            if (array_key_exists('email', $exception->errors())) {
                throw ValidationException::withMessages([
                    'email' => 'We could not start a new application with these details. If you may already have access, sign in or use password recovery.',
                ]);
            }
            throw $exception;
        }

        $user = $application->applicant;
        Auth::login($user);
        $request->session()->regenerate();
        $status = 'Application started. Verify your email to continue.';
        try {
            $emails->sendVerification($user);
        } catch (\Throwable $exception) {
            report($exception);
            $status = 'Application started, but the verification email could not be sent yet. Use Send another link to retry.';
        }

        return redirect()->route('verification.notice')->with('status', $status);
    }
}
