<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PublisherApplications\PublisherApplicantEmailService;
use App\Services\PublisherApplications\PublisherApplicationLegalService;
use App\Services\PublisherApplications\PublisherApplicationReadinessService;
use App\Services\PublisherApplications\PublisherApplicationService;
use App\Services\PublisherApplications\TurnstileVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PublicPublisherRegistrationController extends Controller
{
    public function create(PublisherApplicationReadinessService $readiness, PublisherApplicationLegalService $legal): View|Response
    {
        abort_unless(config('publisher-applications.public_registration_enabled'), 404);
        if (! $readiness->isReady()) {
            return response()->view('auth.publisher-registration-unavailable', status: 503);
        }

        return view('auth.register-publisher', ['legalDocuments' => $legal->documents()]);
    }

    public function store(
        Request $request,
        PublisherApplicationService $applications,
        PublisherApplicationLegalService $legal,
        TurnstileVerifier $turnstile,
        PublisherApplicantEmailService $emails,
        PublisherApplicationReadinessService $readiness,
    ): RedirectResponse|Response {
        abort_unless(config('publisher-applications.public_registration_enabled'), 404);
        if (! $readiness->isReady()) {
            return response()->view('auth.publisher-registration-unavailable', status: 503);
        }

        $legalRules = collect($legal->documents())->mapWithKeys(fn (array $document, string $type): array => [
            'legal.'.$type => $document['required'] ? ['required', 'accepted'] : ['nullable', 'accepted'],
        ])->all();
        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            // Keep a meaningful minimum without forcing composition rules that
            // create avoidable signup friction. Rate limiting, password hashing,
            // lockout controls, and normal password reset protections remain.
            'password' => ['required', 'confirmed', Password::min(10)],
            'publisher_name' => ['required', 'string', 'max:255'],
            // Registration activates the Publisher account only. Website/domain
            // fields are deliberately not accepted here, even if an older client
            // sends them; every website has its own verification and review.
            // Existing legacy applications that already have domain claims remain
            // readable and completable through their compatibility flow.
            '_company_website' => ['nullable', 'string', 'max:0'],
            'legal' => ['required', 'array'],
            'marketing_opt_in' => ['sometimes', 'boolean'],
            'cf-turnstile-response' => [config('publisher-applications.turnstile.enabled') ? 'required' : 'nullable', 'string', 'max:2048'],
        ], $legalRules));
        $turnstile->verify($data['cf-turnstile-response'] ?? null);

        try {
            $application = DB::transaction(function () use ($applications, $legal, $data, $request) {
                $application = $applications->registerActive($data);
                $legal->record($application, $application->applicant, $data, $request);

                return $application;
            });
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

        if (! config('security.authentication.email_verification_required', true)) {
            $applications->emailVerified($user);

            return redirect()->route('dashboard')->with('status', 'Publisher account activated. Your default 70% commercial terms are active.');
        }

        $status = 'Publisher account activated. Verify your email to continue.';
        try {
            $emails->sendVerification($user);
        } catch (\Throwable $exception) {
            report($exception);
            $status = 'Publisher account activated, but the verification email could not be sent yet. Use Send another link to retry.';
        }

        return redirect()->route('verification.notice')->with('status', $status);
    }
}
