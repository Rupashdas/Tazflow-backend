<?php

namespace Database\Seeders;

use App\Actions\CreateWorkspace;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Seeder;

/**
 * Two workspaces to click around in. Every password is Pass123#.
 *
 *   Tazkflow        owner admin@example.com; Rupash and Prottasha are Admins,
 *                Debos, Nishan and Tanjim are Members
 *   Acme Studio  owner Prottasha; Debos is a Member — so switching
 *                workspaces and isolation can both be seen by hand
 */
class DatabaseSeeder extends Seeder {
    public function run(CreateWorkspace $create): void {
        $people = collect([
            'owner'     => ['Tazkflow Admin', 'admin@example.com', 'Owner'],
            'rupash'    => ['Rupash Das', 'rupash.das.202@gmail.com', 'Senior Programmer'],
            'prottasha' => ['Prottasha Das', 'prottasha@gmail.com', 'Big Boss'],
            'debos'     => ['Debos Das', 'debos.das.02@gmail.com', 'Backend Developer'],
            'nishan'    => ['Nishan Das', 'nishandas880@gmail.com', 'Frontend Developer'],
            'tanjim'    => ['Tanjim Ahmmed', 'tanjimahmmed@gmail.com', 'Fullstack Developer'],
        ])->map(function (array $p) {
            $user = User::updateOrCreate(['email' => $p[1]], ['name' => $p[0], 'title' => $p[2], 'password' => 'Pass123#']);
            $user->forceFill(['email_verified_at' => now()])->save();

            return $user;
        });

        $tazkflow = $create($people['owner'], 'Tazkflow', 'tazkflow');
        $this->add($tazkflow, $people['rupash'], 'admin');
        $this->add($tazkflow, $people['prottasha'], 'admin');
        foreach (['debos', 'nishan', 'tanjim'] as $key) {
            $this->add($tazkflow, $people[$key], 'member');
        }

        $acme = $create($people['prottasha'], 'Acme Studio', 'acme-studio');
        $this->add($acme, $people['debos'], 'member');

        $this->command->info('Seeded two workspaces (tazkflow, acme-studio). Every password is Pass123#.');
    }

    // A seeder runs with no current workspace, so the Role scope adds
    // nothing and the workspace has to be named here.
    private function add(Workspace $workspace, User $user, string $role): void {
        WorkspaceMember::create([
            'workspace_id' => $workspace->id,
            'user_id'      => $user->id,
            'role_id'      => Role::where('workspace_id', $workspace->id)->where('name', $role)->value('id'),
        ]);
    }
}
