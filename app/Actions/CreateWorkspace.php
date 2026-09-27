<?php

namespace App\Actions;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\DefaultRoles;
use Illuminate\Support\Facades\DB;

/**
 * The one way a workspace comes into being: the workspace, its default roles
 * and the owner's membership are made together or not at all.
 */
final class CreateWorkspace {
    public function __invoke(User $owner, string $name, ?string $slug = null): Workspace {
        return DB::transaction(function () use ($owner, $name, $slug) {
            $workspace = Workspace::create([
                'name'     => $name,
                'slug'     => $slug ?? Workspace::uniqueSlugFrom($name),
                'owner_id' => $owner->id,
            ]);

            $roles = DefaultRoles::createFor($workspace);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id'      => $owner->id,
                'role_id'      => $roles['admin']->id,
            ]);

            return $workspace;
        });
    }
}
