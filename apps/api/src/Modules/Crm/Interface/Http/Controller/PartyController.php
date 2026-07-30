<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Crm\Application\Service\PartyCodeGenerator;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PortalAccountModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

class PartyController
{
    public function __construct(private readonly TenantContext $tenant) {}

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
            ->addSelect(['portal_email' => PortalAccountModel::query()
                ->select('email')->whereColumn('party_id', 'parties.id')->where('is_active', true)->limit(1),
            ])
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
        $nested = $request->validate([
            'contact' => ['sometimes', 'array'],
            'contact.name' => ['required_with:contact', 'string', 'max:255'],
            'contact.email' => ['nullable', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'array'],
            'address.line1' => ['required_with:address', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.city' => ['required_with:address', 'string', 'max:120'],
            'address.postal_code' => ['nullable', 'string', 'max:20'],
            'address.country_code' => ['required_with:address', 'size:2', 'exists:countries,code2'],
        ]);

        // Le code interne n'est jamais saisi : il part sur les factures, les
        // cotations et la synchronisation comptable, où il doit rester stable et
        // reconnaissable. Laissé à la main, il produisait des fiches hors
        // nomenclature — « DAI », « D&F » — impossibles à trier ou à rapprocher.
        $data['code'] = PartyCodeGenerator::next($this->tenant->id(), (string) $data['type']);

        $party = DB::transaction(function () use ($data, $nested) {
            $party = PartyModel::create($data);

            if (isset($nested['contact'])) {
                $party->contacts()->create([
                    'name' => $nested['contact']['name'],
                    'email' => $nested['contact']['email'] ?? null,
                    'phone' => $nested['contact']['phone'] ?? null,
                    'is_primary' => true,
                ]);
            }
            if (isset($nested['address'])) {
                $party->addresses()->create([
                    'label' => 'main',
                    'line1' => $nested['address']['line1'],
                    'line2' => $nested['address']['line2'] ?? null,
                    'city' => $nested['address']['city'],
                    'postal_code' => $nested['address']['postal_code'] ?? null,
                    'country_code' => strtoupper($nested['address']['country_code']),
                    'is_default' => true,
                ]);
            }

            return $party;
        });

        return response()->json($party->fresh(['contacts', 'addresses']), 201);
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
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:64'],
            'ncc' => ['nullable', 'string', 'max:32'],
            'industry' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'size:3', 'exists:currencies,code'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notification_prefs' => ['sometimes', 'array'],
            'tags' => ['sometimes', 'array'],
            'owner_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);
    }
}
