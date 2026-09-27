<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Invitation;
use App\Models\Role;
use App\Support\CapabilityRegistry;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller {
    /** GET /roles — no workspace filter here: Role's scope adds it. */
    public function index(): AnonymousResourceCollection {
        return RoleResource::collection(Role::withCount('members')->orderBy('id')->get());
    }

    public function show(Role $role): RoleResource {
        return new RoleResource($role->loadCount('members'));
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse {
        $validated = $request->validate($this->capabilityRules() + [
            'name'  => ['required', ...$this->nameRules($current)],
            'label' => ['required', 'string', 'max:100'],
        ]);

        $role = DB::transaction(function () use ($validated) {
            $role = Role::create(['name' => $validated['name'], 'label' => $validated['label']]);
            $role->syncCapabilities($validated['capabilities'] ?? []);

            return $role;
        });

        return (new RoleResource($role->loadCount('members')))->response()->setStatusCode(201);
    }


    public function update(Request $request, Role $role, CurrentWorkspace $current): RoleResource {
        $validated = $request->validate($this->capabilityRules() + [
            'name'  => ['sometimes', 'required', ...$this->nameRules($current, $role)],
            'label' => ['sometimes', 'required', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($role, $validated) {
            $role->update(array_intersect_key($validated, array_flip(['name', 'label'])));

            if (array_key_exists('capabilities', $validated)) {
                $role->syncCapabilities($validated['capabilities']);
            }
        });

        return new RoleResource($role->loadCount('members'));
    }




    public function destroy(Role $role): Response|JsonResponse {
        $count = $role->members()->count();

        // Deleting it would quietly strip these people of every permission.
        if ($count > 0) {
            return response()->json([
                'message' => "This role is held by {$count} " . str('member')->plural($count) . '. Give them another role first.',
            ], 422);
        }

        // Expired ones count too: a resend brings them back to life.
        $pending = Invitation::where('role_id', $role->id)->whereNull('accepted_at')->count();

        // Deleting it would let these people in with no role at all.
        if ($pending > 0) {
            return response()->json([
                'message' => "This role is on {$pending} open " . str('invitation')->plural($pending) . '. Cancel those first.',
            ], 422);
        }

        $role->delete();

        return response()->noContent();
    }

    private function capabilityRules(): array {
        return [
            'capabilities'   => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'distinct', Rule::in(CapabilityRegistry::names())],
        ];
    }

    private function nameRules(CurrentWorkspace $current, ?Role $ignore = null): array {
        return [
            'string',
            'max:50',
            'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            Rule::unique('roles', 'name')->where('workspace_id', $current->id())->ignore($ignore?->id),
        ];
    }
}
