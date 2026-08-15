<?php

namespace App\Services\PublisherApplications;

use App\Mail\PublisherApplicantVerificationMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

final class PublisherApplicantEmailService
{
    public function sendVerification(User $user): void
    {
        if (! $user->isPublisherApplicant()) {
            $user->sendEmailVerificationNotification();
            return;
        }

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
        );

        Mail::to($user->email)->send(new PublisherApplicantVerificationMail($user, $url));
    }
}
