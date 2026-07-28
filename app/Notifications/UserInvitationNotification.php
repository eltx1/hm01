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
            ->line('You have been invited to a Horus Media organization.')
            ->action('Accept invitation', route('invitations.accept.show', $this->token))
            ->line('This secure invitation expires in 48 hours and can be used once.');
    }
}
