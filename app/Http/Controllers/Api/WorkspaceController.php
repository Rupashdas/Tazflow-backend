<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Http\Resources\WorkspaceSummaryResource;
use App\Models\Role;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use App\Support\DefaultRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller {
    /** GET /workspaces — every workspace the caller belongs to, for the switcher. */
    public function index(Request $request): AnonymousResourceCollection {
        $memberships = WorkspaceMember::query()
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->with(['workspace', 'role'])
            ->get()
            ->sortBy(fn(WorkspaceMember $m) => $m->workspace->name)
            ->values();

        return WorkspaceSummaryResource::collection($memberships);
    }

    /** POST /workspaces — the caller becomes its owner and first member. */
    public function store(Request $request): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', ...Workspace::slugRules()],
        ]);

        // Both rows or neither: a workspace nobody belongs to could never be opened.
        $workspace = DB::transaction(function () use ($request, $validated) {
            $workspace = Workspace::create([
                'name'     => $validated['name'],
                'slug'     => $validated['slug'] ?? Workspace::uniqueSlugFrom($validated['name']),
                'owner_id' => $request->user()->id,
            ]);

            $roles = DefaultRoles::createFor($workspace);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id'      => $request->user()->id,
                'role_id'      => $roles['admin']->id,
            ]);

            return $workspace;
        });

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

    /** POST /workspace/transfer-ownership — owner only; the old owner stays on as an Admin. */
    public function transferOwnership(Request $request, CurrentWorkspace $current): WorkspaceResource {
        abort_unless($current->isOwner(), 403, 'Only the owner can hand over the workspace.');

        $workspace = $current->get();
        $validated = $request->validate(['user_id' => ['required', 'integer']]);

        $next = WorkspaceMember::where('user_id', $validated['user_id'])
            ->where('is_active', true)
            ->first();

        abort_if(! $next || $next->user_id === $request->user()->id, 422, 'Ownership can only go to another active member.');

        DB::transaction(function () use ($workspace, $request, $next) {
            $workspace->update(['owner_id' => $next->user_id]);

            // Without this, an outgoing owner who held a weak role would
            // lock themselves out of the workspace they built.
            $adminRoleId = Role::where('name', 'admin')->value('id');
            if ($adminRoleId) {
                WorkspaceMember::where('user_id', $request->user()->id)
                    ->update(['role_id' => $adminRoleId]);
            }
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
}
