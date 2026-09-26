<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceSummaryResource;
use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller {
    /*---------------------------------------------------------------------------
    | Inside the workspace
    ---------------------------------------------------------------------------*/

    public function index(): AnonymousResourceCollection {
        return InvitationResource::collection(
            Invitation::whereNull('accepted_at')->with(['role', 'invitedBy'])->latest()->get()
        );
    }

    public function store(Request $request, CurrentWorkspace $current): JsonResponse {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:255'],
            'email'   => ['required', 'email', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('workspace_id', $current->id())],
        ]);

        $email = Str::lower($validated['email']);

        $alreadyMember = WorkspaceMember::where('workspace_id', $current->id())
            ->whereHas('user', fn($user) => $user->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => 'That person is already a member of this workspace.']);
        }

        $invitation = DB::transaction(function () use ($validated, $email, $request) {
            // A newer invitation replaces any pending one to the same address.
            Invitation::whereNull('accepted_at')->where('email', $email)->delete();

            return Invitation::create([
                'name'       => $validated['name'],
                'email'      => $email,
                'role_id'    => $validated['role_id'],
                'invited_by' => $request->user()->id,
                'token'      => Invitation::generateToken(),
                'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
            ]);
        });

        $this->send($invitation);

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    public function resend(Invitation $invitation): InvitationResource {
        abort_if($invitation->isAccepted(), 422, 'This invitation has already been used.');

        // A fresh token, so a leaked copy of the old email stops working.
        $invitation->update([
            'token'      => Invitation::generateToken(),
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        $this->send($invitation);

        return new InvitationResource($invitation);
    }

    public function destroy(Invitation $invitation): Response {
        abort_if($invitation->isAccepted(), 422, 'This invitation has already been used.');

        $invitation->delete();

        return response()->noContent();
    }

    /*---------------------------------------------------------------------------
    | Public, and for people who already have an account
    ---------------------------------------------------------------------------*/

    public function show(string $token): JsonResponse {
        $invitation = $this->pending($token);

        return response()->json(['data' => [
            'workspace'      => ['name' => $invitation->workspace->name, 'slug' => $invitation->workspace->slug],
            'name'           => $invitation->name,
            'email'          => $invitation->email,
            'role'           => $invitation->role?->label,
            'invited_by'     => $invitation->invitedBy->name,
            'expires_at'     => $invitation->expires_at,
            'account_exists' => User::where('email', $invitation->email)->exists(),
        ]]);
    }

    /** For someone with no account yet: make one and join in the same step. */
    public function accept(Request $request, string $token): JsonResponse {
        $invitation = $this->pending($token);

        if (User::where('email', $invitation->email)->exists()) {
            return response()->json([
                'code'    => 'account_exists',
                'message' => 'You already have an account. Log in to join this workspace.',
            ], 409);
        }

        $validated = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($invitation, $validated) {
            $user = User::create([
                'name'     => $validated['name'] ?? $invitation->name,
                'email'    => $invitation->email,
                'password' => $validated['password'],
            ]);

            // They followed a link that was only ever emailed to this
            // address, which proves the address is theirs.
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->admit($invitation, $user);

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return (new UserResource($user->load('preference')))->response()->setStatusCode(201);
    }

    /** For someone already signed in: the invitation must have been sent to their address. */
    public function join(Request $request, string $token): WorkspaceSummaryResource {
        $invitation = $this->pending($token);

        abort_unless(
            Str::lower($request->user()->email) === Str::lower($invitation->email),
            403,
            'This invitation was sent to a different email address.',
        );

        DB::transaction(fn() => $this->admit($invitation, $request->user()));

        return new WorkspaceSummaryResource(
            WorkspaceMember::where('workspace_id', $invitation->workspace_id)
                ->where('user_id', $request->user()->id)
                ->with(['workspace', 'role'])
                ->sole()
        );
    }

    /*---------------------------------------------------------------------------
    | Internals
    ---------------------------------------------------------------------------*/
    private function pending(string $token): Invitation {
        $invitation = Invitation::where('token', $token)->with(['workspace', 'role', 'invitedBy'])->first();

        abort_if(! $invitation, 404, 'Invitation not found.');
        abort_if($invitation->isAccepted(), 410, 'This invitation has already been used.');
        abort_if($invitation->isExpired(), 410, 'This invitation has expired. Ask for a new one.');

        return $invitation;
    }

    /** An existing membership is left as it is. */
    private function admit(Invitation $invitation, User $user): void {
        WorkspaceMember::firstOrCreate(
            ['workspace_id' => $invitation->workspace_id, 'user_id' => $user->id],
            ['role_id' => $invitation->role_id],
        );

        $invitation->update(['accepted_at' => now()]);
    }

    private function send(Invitation $invitation): void {
        Mail::to($invitation->email)->send(new InvitationMail($invitation->load(['workspace', 'role', 'invitedBy'])));
    }
}
