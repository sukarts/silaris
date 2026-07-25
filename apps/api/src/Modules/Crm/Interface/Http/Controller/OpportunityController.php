<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\OpportunityModel;

class OpportunityController
{
    private const STAGES = ['new', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stage' => ['sometimes', Rule::in(self::STAGES)],
            'owner_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(
            OpportunityModel::with('party:id,name,code')
                ->when($validated['stage'] ?? null, fn ($q, $s) => $q->where('stage', $s))
                ->when($validated['owner_id'] ?? null, fn ($q, $o) => $q->where('owner_id', $o))
                ->orderByDesc('created_at')
                ->cursorPaginate(50),
        );
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(OpportunityModel::create($this->payload($request)), 201);
    }

    public function update(Request $request, string $opportunityId): JsonResponse
    {
        $opportunity = OpportunityModel::findOrFail($opportunityId);
        $opportunity->update($this->payload($request, true));

        return response()->json($opportunity->fresh('party:id,name,code'));
    }

    private function payload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'party_id' => [$required, 'uuid', 'exists:parties,id'],
            'title' => [$required, 'string', 'max:255'],
            'stage' => ['sometimes', Rule::in(self::STAGES)],
            'estimated_value' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'size:3', 'exists:currencies,code'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'owner_id' => [$required, 'uuid', 'exists:users,id'],
            'expected_close_date' => ['nullable', 'date'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
