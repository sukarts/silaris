<?php

declare(strict_types=1);

namespace Silaris\Modules\Crm\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\ComplaintModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

class ComplaintController
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'investigating', 'resolved', 'closed', 'rejected'])],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
        ]);

        return response()->json(
            ComplaintModel::with('party:id,name')
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($validated['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s))
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        $data = $request->validate([
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'shipment_id' => ['nullable', 'uuid', 'exists:shipments,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assignee_id' => ['nullable', 'uuid', 'exists:users,id'],
            'sla_due_at' => ['nullable', 'date'],
        ]);

        $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [$tenant->id(), 'complaint:'.date('Y')])->value;

        return response()->json(ComplaintModel::create([
            ...$data,
            'reference' => sprintf('REC-%d-%04d', date('Y'), $sequence),
        ]), 201);
    }

    /** PATCH /v1/complaints/{id} — avancement / résolution. */
    public function update(Request $request, string $complaintId): JsonResponse
    {
        $complaint = ComplaintModel::findOrFail($complaintId);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'investigating', 'resolved', 'closed', 'rejected'])],
            'severity' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'assignee_id' => ['nullable', 'uuid', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'max:10000'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (($data['status'] ?? null) === 'resolved' && $complaint->resolved_at === null) {
            $data['resolved_at'] = now();
        }
        $complaint->update($data);

        return response()->json($complaint->fresh('party:id,name'));
    }
}
