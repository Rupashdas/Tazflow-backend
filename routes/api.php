<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapabilityController;
use App\Http\Controllers\Api\EmailVerificationNotificationController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\VerifyEmailController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public — rate limited against guessing and abuse
|--------------------------------------------------------------------------
*/

Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink']);
    Route::post('/reset-password', [PasswordResetController::class, 'reset']);

    Route::get('/invitations/{token}', [InvitationController::class, 'show']);
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);
});

// The emailed verification link. `signed` rejects any id or hash that was
// not produced by this server.
Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

/*
|--------------------------------------------------------------------------
| Signed in, no workspace needed
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active'])->group(function () {

    // Auth
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::patch('/user', [ProfileController::class, 'update']);
    Route::put('/user/password', [ProfileController::class, 'updatePassword']);
    Route::post('/user/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::delete('/user/avatar', [ProfileController::class, 'removeAvatar']);

    Route::get('/preferences', [PreferenceController::class, 'show']);
    Route::patch('/preferences', [PreferenceController::class, 'update']);

    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1');

    // Workspaces
    Route::get('/workspaces', [WorkspaceController::class, 'index']);
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('verified');

    Route::post('/invitations/{token}/join', [InvitationController::class, 'join'])->middleware('verified');
});

/*
|--------------------------------------------------------------------------
| Inside a workspace — X-Workspace header required
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'active', 'workspace'])->group(function () {
    Route::get('/workspace', [WorkspaceController::class, 'show']);
    Route::patch('/workspace', [WorkspaceController::class, 'update'])->middleware('capability:workspace.settings.manage');

    Route::middleware('capability:roles.view')->group(function () {
        Route::get('/capabilities', [CapabilityController::class, 'index']);
        Route::get('/roles', [RoleController::class, 'index']);
        Route::get('/roles/{role}', [RoleController::class, 'show']);
    });

    Route::middleware('capability:roles.manage')->group(function () {
        Route::post('/roles', [RoleController::class, 'store']);
        Route::patch('/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
    });

    Route::get('/invitations', [InvitationController::class, 'index'])->middleware('capability:members.view');

    Route::middleware('capability:members.invite')->group(function () {
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::post('/invitations/{invitation}/resend', [InvitationController::class, 'resend']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
    });
});
