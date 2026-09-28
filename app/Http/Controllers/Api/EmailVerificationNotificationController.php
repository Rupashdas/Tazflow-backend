<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller {
    public function store(Request $request): JsonResponse {
        $user = $request->user();

        // Verified, but waiting on a new address: that one still needs its link.
        if ($user->hasVerifiedEmail() && ! $user->pending_email) {
            return response()->json(['message' => 'Your email address is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'A new verification link has been sent.'], 202);
    }
}
