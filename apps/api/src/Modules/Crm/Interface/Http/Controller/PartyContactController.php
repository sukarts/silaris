<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyContactModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;

class PartyContactController
{
    public function store(Request $request, string $partyId): JsonResponse
    {
        PartyModel::findOrFail($partyId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        return response()->json(PartyContactModel::create([...$data, 'party_id' => $partyId]), 201);
    }

    public function update(Request $request, string $partyId, string $contactId): JsonResponse
    {
        $contact = PartyContactModel::where('party_id', $partyId)->findOrFail($contactId);
        $contact->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['nullable', 'string', 'max:64'],
            'is_primary' => ['sometimes', 'boolean'],
        ]));

        return response()->json($contact);
    }

    public function destroy(string $partyId, string $contactId): JsonResponse
    {
        PartyContactModel::where('party_id', $partyId)->findOrFail($contactId)->delete();

        return response()->json(null, 204);
    }
}
