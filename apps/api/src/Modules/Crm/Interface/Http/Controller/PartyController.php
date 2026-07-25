<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;

class PartyController
{
    /** GET /v1/parties — clients, prospects, fournisseurs (filtre type). */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['client', 'prospect', 'supplier'])],
            'supplier_kind' => ['sometimes', 'string', 'max:32'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $parties = PartyModel::query()
            ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($validated['supplier_kind'] ?? null, fn ($q, $k) => $q->where('supplier_kind', $k))
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where(
                fn ($w) => $w->whereLike('name', "%{$s}%")->orWhereLike('code', "%{$s}%"),
            ))
            ->withCount('contacts')
            ->orderBy('name')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return response()->json($parties);
    }

    public function show(string $partyId): JsonResponse
    {
        return response()->json(
            PartyModel::with(['contacts', 'addresses'])->findOrFail($partyId),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $party = PartyModel::create($data);

        return response()->json($party, 201);
    }

    public function update(Request $request, string $partyId): JsonResponse
    {
        $party = PartyModel::findOrFail($partyId);
        $party->update($this->validatePayload($request, $party));

        return response()->json($party->fresh(['contacts', 'addresses']));
    }

    public function destroy(string $partyId): JsonResponse
    {
        PartyModel::findOrFail($partyId)->delete(); // soft delete

        return response()->json(null, 204);
    }

    /** POST /v1/parties/{id}/convert — prospect → client. */
    public function convert(string $partyId): JsonResponse
    {
        $party = PartyModel::where('type', 'prospect')->findOrFail($partyId);
        $party->update(['type' => 'client', 'converted_from_prospect_at' => now()]);

        return response()->json($party->fresh());
    }

    private function validatePayload(Request $request, ?PartyModel $existing = null): array
    {
        return $request->validate([
            'type' => [$existing ? 'sometimes' : 'required', Rule::in(['client', 'prospect', 'supplier'])],
            'kind' => ['sometimes', Rule::in(['company', 'individual'])],
            'supplier_kind' => ['nullable', Rule::in(['ocean_carrier', 'airline', 'trucker', 'customs_agent', 'handler', 'insurer', 'port_agent', 'overseas_agent'])],
            'code' => [$existing ? 'sometimes' : 'required', 'string', 'max:24'],
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'currency_code' => ['nullable', 'size:3', 'exists:currencies,code'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notification_prefs' => ['sometimes', 'array'],
            'tags' => ['sometimes', 'array'],
            'owner_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);
    }
}
