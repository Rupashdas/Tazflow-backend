<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Laravel's own reset email, sent by the queue worker. The link still comes from AppServiceProvider. */
class QueuedResetPassword extends ResetPassword implements ShouldQueue {
    use Queueable;
}
