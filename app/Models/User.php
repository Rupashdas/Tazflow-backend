<?php

namespace App\Models;

use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail {
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    // `is_active` is deliberately not fillable: nobody switches an account
    // off by posting a form field.
    protected $fillable = ['name', 'email', 'password', 'avatar', 'title', 'phone', 'bio', 'location'];

    protected $hidden = ['password', 'remember_token'];

    // Mirrors the column default. Without it a freshly created model has
    // is_active = null until reloaded, and the `active` check reads that as
    // a deactivated account.
    protected $attributes = ['is_active' => true];

    protected function casts(): array {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    protected static function booted(): void {
        // Every account has preferences. Creating them here means no path
        // (sign up, invitation, seeder, factory) can forget to.
        static::created(fn (User $user) => $user->preference()->create());
    }

    public function preference(): HasOne {
        return $this->hasOne(UserPreference::class);
    }

    public function avatarUrl(): ?string {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }

    // Through the queue, like every other email (roadmap rule).
    public function sendEmailVerificationNotification(): void {
        $this->notify(new QueuedVerifyEmail);
    }

    // Through the queue, like every other email (roadmap rule).
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void {
        $this->notify(new QueuedResetPassword($token));
    }

    // While an address change waits, the verification link is for the new
    // address and goes to its inbox; everything else still uses `email`.
    public function getEmailForVerification(): string {
        return $this->pending_email ?? $this->email;
    }

    public function routeNotificationForMail(Notification $notification): string {
        return $notification instanceof VerifyEmail && $this->pending_email ? $this->pending_email : $this->email;
    }
}
