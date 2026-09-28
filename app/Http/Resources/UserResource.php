<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'pending_email'  => $this->pending_email,
            'email_verified' => $this->hasVerifiedEmail(),
            'avatar'         => $this->avatarUrl(),
            'title'          => $this->title,
            'phone'          => $this->phone,
            'bio'            => $this->bio,
            'location'       => $this->location,
            'preferences'    => $this->whenLoaded(
                'preference',
                fn() => new UserPreferenceResource($this->preference),
            ),
        ];
    }
}
