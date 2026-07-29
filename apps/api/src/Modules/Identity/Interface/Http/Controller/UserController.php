<?php

declare(strict_types=1);

namespace Silaris\Modules\Identity\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Silaris\Modules\Identity\Infrastructure\Persistence\Model\UserModel;
use Silaris\Modules\Notifications\Infrastructure\Mail\UserInvitationMail;
use Throwable;

class UserController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            UserModel::with(['roles:id,key,name', 'branches:id,code,name'])
                ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(
                    fn ($w) => $w->whereLike('first_name', "%{$s}%")->orWhereLike('last_name', "%{$s}%")->orWhereLike('email', "%{$s}%"),
                ))
                ->when(isset($validated['active']), fn ($q) => $q->where('is_active', $validated['active']))
                ->orderBy('last_name')
                ->cursorPaginate(25),
        );
    }

    /** POST /v1/admin/users — création avec mot de passe provisoire (invitation email : module Notifications). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:fr,en'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['uuid', 'exists:roles,id'],
            'service_id' => ['sometimes', 'nullable', 'uuid', 'exists:services,id'],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['uuid', 'exists:branches,id'],
        ]);

        // Alphabet sans caractères ambigus : le mot de passe est recopié/transmis.
        $temporaryPassword = self::unambiguousPassword();

        $user = DB::transaction(function () use ($data, $temporaryPassword) {
            $user = UserModel::create([
                'email' => strtolower($data['email']),
                'password_hash' => Hash::make($temporaryPassword),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? 'fr',
                'password_changed_at' => now()->subYears(10), // force le changement au 1er login
            ]);
            $user->roles()->sync($data['role_ids']);
            $user->branches()->sync($data['branch_ids']);

            return $user;
        });

        $tenantName = (string) (DB::table('tenants')->where('id', $user->tenant_id)->value('name') ?? 'SILARIS');
        $invitationSent = true;
        try {
            Mail::to($user->email)->send(new UserInvitationMail($user->first_name, $user->email, $temporaryPassword, $tenantName));
        } catch (Throwable $e) {
            $invitationSent = false;
            Log::error("Échec envoi invitation à {$user->email} : {$e->getMessage()}");
        }

        return response()->json([
            'user' => $user->fresh(['roles:id,key,name', 'branches:id,code']),
            // Filet : affiché à l'admin si l'email n'est pas parti (SMTP down, adresse invalide…).
            'temporary_password' => $invitationSent ? null : $temporaryPassword,
            'invitation_sent' => $invitationSent,
        ], 201);
    }

    private static function unambiguousPassword(int $length = 16): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        $user = UserModel::findOrFail($userId);
        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'in:fr,en'],
            'is_active' => ['sometimes', 'boolean'],
            'role_ids' => ['sometimes', 'array', 'min:1'],
            'role_ids.*' => ['uuid', 'exists:roles,id'],
            'branch_ids' => ['sometimes', 'array', 'min:1'],
            'branch_ids.*' => ['uuid', 'exists:branches,id'],
            // Le service détermine quel chef valide ses dossiers.
            'service_id' => ['sometimes', 'nullable', 'uuid', 'exists:services,id'],
        ]);

        DB::transaction(function () use ($user, $data): void {
            if (isset($data['role_ids'])) {
                $user->roles()->sync($data['role_ids']);
            }
            if (isset($data['branch_ids'])) {
                $user->branches()->sync($data['branch_ids']);
            }
            $user->update(collect($data)->except(['role_ids', 'branch_ids'])->all());
        });
        UserModel::forgetPermissionCache($user->id);

        return response()->json($user->fresh(['roles:id,key,name', 'branches:id,code']));
    }

    /** POST /v1/admin/users/{id}/reset-mfa */
    public function resetMfa(string $userId): JsonResponse
    {
        UserModel::findOrFail($userId)->update([
            'mfa_secret' => null, 'mfa_enabled' => false, 'mfa_recovery_codes' => null,
        ]);

        return response()->json(['reset' => true]);
    }
}
