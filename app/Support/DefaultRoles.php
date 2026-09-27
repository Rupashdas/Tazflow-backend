<?php

namespace App\Support;

use App\Models\Role;
use App\Models\Workspace;

/**
 * The roles every new workspace starts with. A starting point, not a
 * hierarchy: the owner can rename, change or delete them afterwards, except
 * that the Admin role cannot be deleted and always holds every capability.
 */
final class DefaultRoles {
    public const DEFINITIONS = [
        'admin'  => ['label' => 'Admin',  'is_admin' => true],
        'member' => ['label' => 'Member', 'capabilities' => ['members.view', 'roles.view']],
        'guest'  => ['label' => 'Guest',  'capabilities' => []],
    ];

    /** @return array<string, Role> keyed by role name */
    public static function createFor(Workspace $workspace): array {
        $roles = [];

        foreach (self::DEFINITIONS as $name => $definition) {
            $role = Role::create([
                'workspace_id' => $workspace->id,
                'name'         => $name,
                'label'        => $definition['label'],
                'is_admin'     => $definition['is_admin'] ?? false,
            ]);
            // Admin stores nothing: it reads the registry (Role::capabilityNames).
            $role->syncCapabilities($definition['capabilities'] ?? []);
            $roles[$name] = $role;
        }

        return $roles;
    }
}
