<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Http\Resources\WorkspaceSummaryResource;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller {
    /** GET /workspaces — every workspace the caller belongs to, for the switcher. */
    public function index(Request $request): AnonymousResourceCollection {
        $memberships = WorkspaceMember::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->whereHas('workspace')
            ->with(['workspace', 'role'])
            ->get()
            ->sortBy(fn(WorkspaceMember $m) => $m->workspace->name)
            ->values();

        return WorkspaceSummaryResource::collection($memberships);
    }

    /** POST /workspaces — the caller becomes its owner and first member. */
    public function store(Request $request, CreateWorkspace $create): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', ...Workspace::slugRules()],
        ]);

        $workspace = $create($request->user(), $validated['name'], $validated['slug'] ?? null);

        return (new WorkspaceResource($workspace))->response()->setStatusCode(201);
    }

    /** GET /workspace — the workspace named by the X-Workspace header. */
    public function show(CurrentWorkspace $current): WorkspaceResource {
        return new WorkspaceResource($current->get());
    }

    /** PATCH /workspace */
    public function update(Request $request, CurrentWorkspace $current): WorkspaceResource {
        $workspace = $current->get();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'slug' => ['sometimes', 'required', ...Workspace::slugRules($workspace->id)],
        ]);

        $workspace->update($validated);

        return new WorkspaceResource($workspace);
    }

    /** POST /workspace/transfer-ownership — owner only; the new owner becomes an Admin, and the old owner stays on as one. */
    public function transferOwnership(Request $request, CurrentWorkspace $current): WorkspaceResource {
        abort_unless($current->isOwner(), 403, 'Only the owner can hand over the workspace.');

        $workspace = $current->get();
        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $next = WorkspaceMember::where('user_id', $validated['user_id'])
            ->where('is_active', true)
            ->first();

        abort_if(! $next || $next->user_id === $request->user()->id, 422, 'Ownership can only go to another active member.');

        DB::transaction(function () use ($workspace, $current, $next) {
            $workspace->update(['owner_id' => $next->user_id]);

            // The owner always holds the Admin role. is_admin, not the name:
            // the Admin role can be renamed but never deleted, so this always
            // finds it. The old owner is updated through the membership
            // CurrentWorkspace holds, so the "me" in this response sees the
            // new role too.
            $adminRoleId = Role::where('is_admin', true)->value('id');
            $next->update(['role_id' => $adminRoleId]);
            $current->membership()->update(['role_id' => $adminRoleId]);
        });

        return new WorkspaceResource($workspace->refresh());
    }

    /** POST /workspace/leave — any member may walk out; the owner hands over first. */
    public function leave(Request $request, CurrentWorkspace $current): JsonResponse {
        abort_if($current->isOwner(), 422, 'The owner cannot leave. Hand over ownership first.');

        // When projects arrive, this is where their memberships and task
        // assignments in this workspace get cleaned up too (same as MemberController@destroy).
        WorkspaceMember::where('user_id', $request->user()->id)->delete();

        return response()->json(['message' => 'You have left the workspace.']);
    }

    /** DELETE /workspace — owner only. A soft delete, so a mistake can still be undone by hand. */
    public function destroy(Request $request, CurrentWorkspace $current): JsonResponse {
        abort_unless($current->isOwner(), 403, 'Only the owner can delete the workspace.');

        $workspace = $current->get();

        // Typing the slug is the "are you sure": one stray click cannot wipe a team's work.
        if ($request->input('confirm') !== $workspace->slug) {
            throw ValidationException::withMessages(['confirm' => "Type {$workspace->slug} to confirm."]);
        }

        DB::transaction(function () use ($workspace) {
            // An open invitation would lead to a workspace that is no longer there.
            Invitation::whereNull('accepted_at')->delete();

            $workspace->delete();
        });

        return response()->json(['message' => 'The workspace has been deleted.']);
    }
}
