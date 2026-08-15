<?php

namespace App\Notifications;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(private UserInvitation $invitation, private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Horus Media invitation')
            ->view('emails.auth-action', [
                'title' => 'Your Horus Media invitation',
                'heading' => 'You are invited to Horus Media',
                'lines' => ['An administrator invited this email address to a Horus Media organization.'],
                'actionText' => 'Accept invitation',
                'actionUrl' => route('invitations.accept.show', $this->token),
                'afterLines' => ['This secure invitation expires in 48 hours and can be used once. If you were not expecting this invitation, you can ignore this email.'],
            ]);
    }
}
