<?php

namespace App\Http\Resources;

use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A person as a member of the current workspace. Wraps a WorkspaceMember. */
class MemberResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id'        => $this->user->id,
            'name'      => $this->user->name,
            'email'     => $this->user->email,
            'avatar'    => $this->user->avatarUrl(),
            'title'     => $this->user->title,
            'role'      => $this->role ? ['id' => $this->role->id, 'name' => $this->role->name, 'label' => $this->role->label] : null,
            'is_owner'  => app(CurrentWorkspace::class)->get()->isOwnedBy($this->user),
            'joined_at' => $this->created_at,
        ];
    }
}
