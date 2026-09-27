<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admins manage memberships — role, access, removal — never the accounts
 * behind them. An account is shared across workspaces; editing someone's
 * email here would hand their other workspaces to this one's admin.
 */
class MemberController extends Controller {
    public function index(Request $request, CurrentWorkspace $current): AnonymousResourceCollection {
        $search = $request->string('search')->trim()->value();

        $members = WorkspaceMember::query()
            ->where('workspace_id', $current->id())
            ->with(['user', 'role'])
            ->when($search !== '', fn ($query) => $query->whereHas('user', function ($user) use ($search) {
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $user->where('name', 'like', $like)->orWhere('email', 'like', $like);
            }))
            ->orderBy(User::select('name')->whereColumn('users.id', 'workspace_members.user_id'))
            ->paginate(min((int) $request->input('per_page', 50), 100));

        return MemberResource::collection($members);
    }

    public function updateRole(Request $request, User $user, CurrentWorkspace $current): MemberResource {
        $membership = WorkspaceMember::where('workspace_id', $current->id())
            ->where('user_id', $user->id)
            ->firstOr(fn () => abort(404, 'That person is not a member of this workspace.'));

        abort_if($current->get()->isOwnedBy($user), 422, 'The owner\'s role cannot be changed. Hand over ownership first.');

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $current->id())],
        ]);

        $membership->update(['role_id' => $validated['role_id']]);

        return new MemberResource($membership->load(['user', 'role']));
    }
}
