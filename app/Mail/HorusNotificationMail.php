<?php

namespace App\Mail;

use App\Models\HorusNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class HorusNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly HorusNotification $item) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Horus Media] '.$this->item->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.horus-notification');
    }
}
