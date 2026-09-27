<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'label'        => $this->label,
            'is_admin'     => $this->is_admin,
            'capabilities' => $this->capabilityNames(),
            'members_count' => $this->whenCounted('members'),
        ];
    }
}
