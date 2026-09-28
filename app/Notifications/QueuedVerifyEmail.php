<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Laravel's own verification email, sent by the queue worker instead of inside the request. */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue {
    use Queueable;
}
