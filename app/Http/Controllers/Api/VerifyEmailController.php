<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller {
    /**
     * The link in the verification email.
     *
     * The route is `signed`, so the id and hash cannot be forged or edited.
     * It does not require a session: people often open email on a different
     * device from the one they signed up on.
     */
    public function __invoke(int $id, string $hash): RedirectResponse {
        $user = User::findOrFail($id);
        $to = rtrim(config('app.frontend_url'), '/') . '/email-verified';

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if ($user->pending_email) {
            // Someone may have signed up with it while the link waited in the inbox.
            if (User::where('email', $user->pending_email)->exists()) {
                $user->forceFill(['pending_email' => null])->save();

                return redirect()->away($to . '?error=email_taken');
            }

            $user->forceFill(['email' => $user->pending_email, 'pending_email' => null, 'email_verified_at' => now()])->save();
        } elseif (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away($to);
    }
}
