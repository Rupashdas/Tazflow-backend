<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberResource;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Admins manage memberships — role, access, removal — never the accounts
 * behind them. An account is shared across workspaces; editing someone's
 * email here would hand their other workspaces to this one's admin.
 */
class MemberController extends Controller {
    public function index(Request $request): AnonymousResourceCollection {
        $search = $request->string('search')->trim()->value();

        $members = WorkspaceMember::query()
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
        $membership = $this->membershipOf($user);
        $this->refuseForOwner($user, $current, 'The owner\'s role cannot be changed. Hand over ownership first.');

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $current->id())],
        ]);

        $membership->update(['role_id' => $validated['role_id']]);

        return new MemberResource($membership->load(['user', 'role']));
    }

    public function toggleActive(Request $request, User $user, CurrentWorkspace $current): MemberResource {
        $membership = $this->membershipOf($user);
        $this->refuseForOwner($user, $current, 'The owner cannot be deactivated.');
        abort_if($user->is($request->user()), 422, 'You cannot deactivate yourself.');

        $membership->update(['is_active' => ! $membership->is_active]);

        return new MemberResource($membership->load(['user', 'role']));
    }

    public function destroy(Request $request, User $user, CurrentWorkspace $current): JsonResponse {
        $membership = $this->membershipOf($user);
        $this->refuseForOwner($user, $current, 'The owner cannot be removed. Hand over ownership first.');
        abort_if($user->is($request->user()), 422, 'You cannot remove yourself.');

        // When projects arrive, this is where their memberships and task
        // assignments in this workspace get cleaned up too.
        $membership->delete();

        return response()->json(['message' => 'Removed from the workspace.']);
    }

    // BelongsToWorkspace keeps this inside the current workspace.
    private function membershipOf(User $user): WorkspaceMember {
        return WorkspaceMember::where('user_id', $user->id)
            ->firstOr(fn () => abort(404, 'That person is not a member of this workspace.'));
    }

    private function refuseForOwner(User $user, CurrentWorkspace $current, string $message): void {
        abort_if($current->get()->isOwnedBy($user), 422, $message);
    }
}
