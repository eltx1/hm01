<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PublisherApplicantVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly User $applicant, public readonly string $verificationUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Horus Media] Verify your Publisher application email');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.publisher-applicant-verification');
    }
}
