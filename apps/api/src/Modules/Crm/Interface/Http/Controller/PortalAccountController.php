<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Silaris\Modules\Notifications\Infrastructure\Mail\PortalInvitationMail;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Throwable;

/**
 * Invitation d'un client à son espace portail : crée le compte (ou régénère le
 * mot de passe d'un compte existant — « réinviter ») et envoie l'email
 * d'invitation avec identifiants provisoires.
 */
class PortalAccountController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function invite(Request $request, string $partyId): JsonResponse
    {
        $party = PartyModel::findOrFail($partyId);
        if ($party->type !== 'client') {
            throw ValidationException::withMessages(['party' => ['Seul un client peut être invité au portail.']]);
        }

        $data = $request->validate(['email' => ['sometimes', 'nullable', 'email', 'max:255']]);
        $account = PortalAccountModel::where('party_id', $party->id)->first();

        $email = strtolower((string) ($data['email']
            ?? $account->email
            ?? DB::table('party_contacts')->where('party_id', $party->id)->orderByDesc('is_primary')->value('email')));
        if ($email === '') {
            throw ValidationException::withMessages(['email' => ['Aucun email : renseignez un contact ou fournissez une adresse.']]);
        }

        $temporaryPassword = self::unambiguousPassword();

        if ($account === null) {
            $account = PortalAccountModel::create([
                'party_id' => $party->id,
                'email' => $email,
                'password_hash' => Hash::make($temporaryPassword),
                'name' => $party->name,
                'is_active' => true,
            ]);
        } else {
            $account->update(['email' => $email, 'password_hash' => Hash::make($temporaryPassword), 'is_active' => true]);
        }

        $tenantName = (string) (DB::table('tenants')->where('id', $this->tenant->id())->value('name') ?? 'SILARIS');
        $invitationSent = true;
        try {
            Mail::to($email)->send(new PortalInvitationMail($party->name, $email, $temporaryPassword, $tenantName));
        } catch (Throwable $e) {
            $invitationSent = false;
            Log::error("Échec envoi invitation portail à {$email} : {$e->getMessage()}");
        }

        return response()->json([
            'portal_account' => ['id' => $account->id, 'email' => $account->email, 'is_active' => $account->is_active],
            'invitation_sent' => $invitationSent,
            // Filet : montré à l'agent uniquement si l'email n'est pas parti.
            'temporary_password' => $invitationSent ? null : $temporaryPassword,
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
}
