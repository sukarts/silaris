<?php

declare(strict_types=1);

namespace Silaris\Modules\Expense\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Expense\Application\Service\MarginCalculator;
use Silaris\Modules\Expense\Infrastructure\Persistence\Model\ExpenseModel;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

/**
 * Dépenses d'un dossier — factures fournisseurs, et la marge réelle qu'elles
 * dessinent face à la prévision de la cotation.
 */
class ExpenseController
{
    public function __construct(private readonly MarginCalculator $margin) {}

    /** GET /v1/expenses — toutes les dépenses, tous dossiers, filtrables. */
    public function all(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['recorded', 'validated', 'paid', 'cancelled'])],
            'shipment_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        return response()->json(
            ExpenseModel::with(['supplier:id,code,name', 'shipment:id,reference'])
                ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['shipment_id'] ?? null, fn ($q, $id) => $q->where('shipment_id', $id))
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->whereLike('label', "%{$s}%"))
                ->orderByDesc('created_at')
                ->cursorPaginate(30),
        );
    }

    /** GET /v1/shipments/{id}/expenses — dépenses du dossier + marge. */
    public function index(string $shipmentId): JsonResponse
    {
        ShipmentModel::findOrFail($shipmentId);

        return response()->json([
            'data' => ExpenseModel::where('shipment_id', $shipmentId)
                ->with('supplier:id,code,name')
                ->orderByDesc('created_at')
                ->get(),
            'margin' => $this->margin->forShipment($shipmentId),
        ]);
    }

    /** POST /v1/shipments/{id}/expenses */
    public function store(Request $request, string $shipmentId): JsonResponse
    {
        $shipment = ShipmentModel::findOrFail($shipmentId);
        $data = $this->validatePayload($request);

        $expense = ExpenseModel::create([
            ...$data,
            'shipment_id' => $shipmentId,
            'company_id' => $shipment->company_id,
            'recorded_by' => $request->user()?->id,
        ]);

        return response()->json($expense->fresh('supplier:id,code,name'), 201);
    }

    /** PATCH /v1/expenses/{id} */
    public function update(Request $request, string $expenseId): JsonResponse
    {
        $expense = ExpenseModel::findOrFail($expenseId);
        $expense->update($this->validatePayload($request, partial: true));

        return response()->json($expense->fresh('supplier:id,code,name'));
    }

    /** DELETE /v1/expenses/{id} */
    public function destroy(string $expenseId): JsonResponse
    {
        ExpenseModel::findOrFail($expenseId)->delete();

        return response()->json(null, 204);
    }

    /** POST /v1/expenses/{id}/validate — contrôle d'une dépense avant qu'elle pèse dans la marge. */
    public function validateExpense(Request $request, string $expenseId): JsonResponse
    {
        $expense = ExpenseModel::whereIn('status', ['recorded', 'validated'])->findOrFail($expenseId);
        $status = $request->validate(['status' => ['required', Rule::in(['validated', 'paid'])]])['status'];

        $expense->update([
            'status' => $status,
            'validated_by' => $expense->validated_by ?? $request->user()?->id,
            'validated_at' => $expense->validated_at ?? now(),
        ]);

        return response()->json($expense->fresh('supplier:id,code,name'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'supplier_id' => ['nullable', 'uuid', Rule::exists('parties', 'id')->where('type', 'supplier')],
            'service_code' => ['nullable', 'string', 'max:32'],
            'label' => [$req, 'string', 'max:200'],
            'amount' => [$req, 'numeric', 'min:0'],
            'currency_code' => [$req, 'string', 'size:3', 'exists:currencies,code'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:64'],
            'invoice_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['recorded', 'validated', 'paid', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
