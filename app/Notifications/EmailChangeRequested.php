<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the current address when someone asks to move the account to another one. */
class EmailChangeRequested extends Notification implements ShouldQueue {
    use Queueable;

    public function __construct(public string $newEmail) {}

    public function via(object $notifiable): array {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage {
        return (new MailMessage)
            ->subject('Your email address is about to change')
            ->line("Someone signed in to your account asked to change its email address to {$this->newEmail}.")
            ->line('Nothing changes until that address is confirmed.')
            ->line('If this was not you, change your password now.');
    }
}
