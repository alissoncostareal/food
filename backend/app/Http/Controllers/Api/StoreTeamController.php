<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreInvitation;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\MerchantStoreResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Throwable;

class StoreTeamController extends Controller
{
    public function __construct(
        private readonly MerchantStoreResolver $merchantStoreResolver
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $store = $this->merchantStoreResolver->resolveOrFail($user);

        if (!$user->canManageStoreTeam($store)) {
            return response()->json([
                'message' => 'Apenas o dono da loja pode gerenciar a equipe.',
            ], 403);
        }

        if (! $store->canUseFeature('team')) {
            return response()->json([
                'message' => 'Gestão de equipe disponível no plano Premium.',
            ], 403);
        }

        $members = $store->members()
            ->with(['user:id,name,email,role', 'invitedBy:id,name'])
            ->latest()
            ->get()
            ->map(fn (StoreMember $member) => [
                'id' => $member->id,
                'role' => $member->role,
                'user' => [
                    'id' => $member->user->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                    'role' => $member->user->role,
                ],
                'invited_by' => $member->invitedBy?->name,
                'created_at' => $member->created_at,
            ]);

        $invitations = $store->invitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->map(fn (StoreInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
                'invite_url' => $this->inviteUrl($invitation->token),
            ]);

        return response()->json([
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
            ],
            'members' => $members,
            'invitations' => $invitations,
            'roles' => [
                ['value' => StoreMember::ROLE_MANAGER, 'label' => 'Gerente'],
                ['value' => StoreMember::ROLE_STAFF, 'label' => 'Operação'],
            ],
            'limits' => [
                'max_team_members' => $store->maxTeamMembersAllowed(),
                'current_members' => $store->members()->count(),
                'can_add_member' => ! $store->teamLimitReached(),
            ],
        ]);
    }

    public function storeMember(Request $request)
    {
        $user = $request->user();
        $store = $this->merchantStoreResolver->resolveOrFail($user);

        if (!$user->canManageStoreTeam($store)) {
            return response()->json([
                'message' => 'Apenas o dono da loja pode adicionar funcionários.',
            ], 403);
        }

        if (! $store->canUseFeature('team')) {
            return response()->json([
                'message' => 'Gestão de equipe disponível no plano Premium.',
            ], 403);
        }

        if ($store->teamLimitReached()) {
            return response()->json([
                'message' => 'Limite de funcionários do plano atingido.',
            ], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([StoreMember::ROLE_MANAGER, StoreMember::ROLE_STAFF])],
        ]);

        try {
            $member = DB::transaction(function () use ($validated, $store, $user) {
                $employee = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'role' => User::ROLE_STORE_STAFF,
                    'current_store_id' => $store->id,
                ]);

                return StoreMember::create([
                    'store_id' => $store->id,
                    'user_id' => $employee->id,
                    'role' => $validated['role'],
                    'invited_by_user_id' => $user->id,
                ])->load('user:id,name,email,role');
            });

            return response()->json([
                'message' => 'Funcionário criado com sucesso.',
                'member' => [
                    'id' => $member->id,
                    'role' => $member->role,
                    'user' => $member->user,
                ],
            ], 201);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível criar o funcionário.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function invite(Request $request)
    {
        $user = $request->user();
        $store = $this->merchantStoreResolver->resolveOrFail($user);

        if (!$user->canManageStoreTeam($store)) {
            return response()->json([
                'message' => 'Apenas o dono da loja pode convidar funcionários.',
            ], 403);
        }

        if (! $store->canUseFeature('team')) {
            return response()->json([
                'message' => 'Gestão de equipe disponível no plano Premium.',
            ], 403);
        }

        if ($store->teamLimitReached()) {
            return response()->json([
                'message' => 'Limite de funcionários do plano atingido.',
            ], 422);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([StoreMember::ROLE_MANAGER, StoreMember::ROLE_STAFF])],
        ]);

        $email = strtolower($validated['email']);

        if ($store->user?->email === $email) {
            return response()->json([
                'message' => 'O dono da loja já possui acesso total.',
            ], 422);
        }

        $existingMember = $store->members()
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->exists();

        if ($existingMember) {
            return response()->json([
                'message' => 'Este e-mail já faz parte da equipe.',
            ], 422);
        }

        $store->invitations()
            ->whereNull('accepted_at')
            ->where('email', $email)
            ->where('expires_at', '>', now())
            ->delete();

        $invitation = StoreInvitation::create([
            'store_id' => $store->id,
            'email' => $email,
            'role' => $validated['role'],
            'token' => StoreInvitation::generateToken(),
            'invited_by_user_id' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'message' => 'Convite gerado com sucesso.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at,
                'invite_url' => $this->inviteUrl($invitation->token),
            ],
        ], 201);
    }

    public function destroyMember(Request $request, StoreMember $member)
    {
        $user = $request->user();
        $store = $this->merchantStoreResolver->resolveOrFail($user);

        if (!$user->canManageStoreTeam($store) || (int) $member->store_id !== (int) $store->id) {
            return response()->json([
                'message' => 'Acesso negado.',
            ], 403);
        }

        $memberUser = $member->user;
        $member->delete();

        $memberUser?->tokens()->delete();

        if ((int) $memberUser?->current_store_id === (int) $store->id) {
            $memberUser->update(['current_store_id' => null]);
        }

        return response()->json([
            'message' => 'Funcionário removido da equipe. Acesso revogado.',
        ]);
    }

    public function cancelInvitation(Request $request, StoreInvitation $invitation)
    {
        $user = $request->user();
        $store = $this->merchantStoreResolver->resolveOrFail($user);

        if (!$user->canManageStoreTeam($store) || (int) $invitation->store_id !== (int) $store->id) {
            return response()->json([
                'message' => 'Acesso negado.',
            ], 403);
        }

        $invitation->delete();

        return response()->json([
            'message' => 'Convite cancelado.',
        ]);
    }

    public function showInvitation(string $token)
    {
        $invitation = StoreInvitation::query()
            ->with('store:id,name')
            ->where('token', $token)
            ->first();

        if (!$invitation || !$invitation->isPending()) {
            return response()->json([
                'message' => 'Convite inválido ou expirado.',
            ], 404);
        }

        $existingUser = User::where('email', $invitation->email)->first();

        return response()->json([
            'email' => $invitation->email,
            'role' => $invitation->role,
            'store' => [
                'id' => $invitation->store->id,
                'name' => $invitation->store->name,
            ],
            'requires_registration' => !$existingUser,
            'expires_at' => $invitation->expires_at,
        ]);
    }

    public function acceptInvitation(Request $request, string $token)
    {
        $invitation = StoreInvitation::query()
            ->with('store')
            ->where('token', $token)
            ->first();

        if (!$invitation || !$invitation->isPending()) {
            return response()->json([
                'message' => 'Convite inválido ou expirado.',
            ], 404);
        }

        $existingUser = User::where('email', $invitation->email)->first();

        $validated = $request->validate([
            'name' => [$existingUser ? 'nullable' : 'required', 'string', 'max:255'],
            'password' => [$existingUser ? 'nullable' : 'required', 'string', 'min:8'],
        ]);

        try {
            $result = DB::transaction(function () use ($invitation, $existingUser, $validated) {
                if ($existingUser) {
                    if ($existingUser->isStoreOwner()) {
                        throw new \RuntimeException('Este e-mail já é dono de uma loja.');
                    }

                    $employee = $existingUser;

                    if ($employee->role === User::ROLE_CUSTOMER) {
                        $employee->update(['role' => User::ROLE_STORE_STAFF]);
                    } elseif (!$employee->isStoreStaff()) {
                        throw new \RuntimeException('Este e-mail não pode ser vinculado como funcionário.');
                    }
                } else {
                    $employee = User::create([
                        'name' => $validated['name'],
                        'email' => $invitation->email,
                        'password' => Hash::make($validated['password']),
                        'role' => User::ROLE_STORE_STAFF,
                    ]);
                }

                $employee->update(['current_store_id' => $invitation->store_id]);

                StoreMember::updateOrCreate(
                    [
                        'store_id' => $invitation->store_id,
                        'user_id' => $employee->id,
                    ],
                    [
                        'role' => $invitation->role,
                        'invited_by_user_id' => $invitation->invited_by_user_id,
                    ]
                );

                $invitation->update(['accepted_at' => now()]);

                $token = $employee->createToken('auth_token')->plainTextToken;

                return [
                    'token' => $token,
                    'user' => $employee->load(['storeMemberships.store', 'currentStore']),
                ];
            });

            return response()->json([
                'message' => 'Convite aceito com sucesso.',
                'data' => $result,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Não foi possível aceitar o convite.',
                'details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    private function inviteUrl(string $token): string
    {
        $adminUrl = rtrim((string) config('app.admin_url', env('ADMIN_APP_URL', 'http://localhost:5175')), '/');

        return "{$adminUrl}/convite/{$token}";
    }
}
