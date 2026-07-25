<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Interface\Http\Controller;

use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Pricing\Domain\Service\CargoSpec;
use Silaris\Modules\Pricing\Domain\Service\QuoteCalculator;
use Silaris\Modules\Pricing\Infrastructure\Persistence\Model\QuoteModel;
use Silaris\Modules\Shared\Application\Bus\CommandBus;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Application\Command\CreateShipment\CreateShipmentCommand;

class QuoteController
{
    public function __construct(
        private readonly QuoteCalculator $calculator,
        private readonly CommandBus $commands,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'accepted', 'rejected', 'expired'])],
            'party_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(
            QuoteModel::with('party:id,name,code')
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($validated['party_id'] ?? null, fn ($q, $p) => $q->where('party_id', $p))
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function show(string $quoteId): JsonResponse
    {
        return response()->json(QuoteModel::with(['lines', 'party:id,name,code'])->findOrFail($quoteId));
    }

    /** POST /v1/quotes/calculate — simulation sans persistance (moteur de cotation). */
    public function calculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road'])],
            'origin_locode' => ['required', 'size:5'],
            'destination_locode' => ['required', 'size:5'],
            'party_id' => ['nullable', 'uuid', 'exists:parties,id'],
            'containers' => ['sometimes', 'array'],
            'gross_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'declared_value' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $lines = $this->calculator->calculate(
            mode: $data['mode'],
            originLocode: strtoupper($data['origin_locode']),
            destinationLocode: strtoupper($data['destination_locode']),
            cargo: new CargoSpec(
                containers: $data['containers'] ?? [],
                grossWeightKg: (float) ($data['gross_weight_kg'] ?? 0),
                volumeM3: (float) ($data['volume_m3'] ?? 0),
                declaredValue: (float) ($data['declared_value'] ?? 0),
            ),
            date: new DateTimeImmutable,
            partyId: $data['party_id'] ?? null,
        );

        return response()->json(['lines' => $lines]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'owner_id' => ['required', 'uuid', 'exists:users,id'],
            'mode' => ['required', Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road', 'multimodal'])],
            'direction' => ['required', Rule::in(['import', 'export', 'transit'])],
            'origin_locode' => ['required', 'size:5'],
            'destination_locode' => ['required', 'size:5'],
            'incoterm_code' => ['required', 'size:3', 'exists:incoterms,code'],
            'currency_code' => ['required', 'size:3', 'exists:currencies,code'],
            'cargo_summary' => ['sometimes', 'array'],
            'valid_until' => ['required', 'date', 'after:today'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_code' => ['required', 'string', 'max:32'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit' => ['required', Rule::in(['container', 'kg', 'm3', 'wm', 'flat', 'percent', 'unit'])],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.currency_code' => ['required', 'size:3', 'exists:currencies,code'],
            'lines.*.buy_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $quote = DB::transaction(function () use ($data) {
            $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [$this->tenant->id(), 'quote:'.date('Y')])->value;

            $lines = $data['lines'];
            unset($data['lines']);

            $quote = QuoteModel::create([
                ...$data,
                'number' => sprintf('Q-%d-%04d', date('Y'), $sequence),
                'total_amount' => collect($lines)->sum(fn ($l) => round($l['quantity'] * $l['unit_price'], 2)),
                'total_buy_amount' => collect($lines)->whereNotNull('buy_price')->sum(fn ($l) => round($l['quantity'] * $l['buy_price'], 2)),
            ]);
            foreach ($lines as $i => $line) {
                $quote->lines()->create([...$line, 'position' => $i + 1]);
            }

            return $quote;
        });

        return response()->json($quote->fresh(['lines']), 201);
    }

    /** POST /v1/quotes/{id}/send */
    public function send(string $quoteId): JsonResponse
    {
        $quote = QuoteModel::where('status', 'draft')->findOrFail($quoteId);
        $quote->update(['status' => 'sent', 'sent_at' => now()]);
        // Notification email au client : module Notifications (listener sur quote.sent).

        return response()->json($quote->fresh());
    }

    /** POST /v1/quotes/{id}/accept — conversion optionnelle en dossier. */
    public function accept(Request $request, string $quoteId): JsonResponse
    {
        $quote = QuoteModel::where('status', 'sent')->findOrFail($quoteId);
        $data = $request->validate([
            'create_shipment' => ['sometimes', 'boolean'],
            'branch_id' => ['required_if:create_shipment,true', 'uuid', 'exists:branches,id'],
            'agent_id' => ['required_if:create_shipment,true', 'uuid', 'exists:users,id'],
        ]);

        $quote->update(['status' => 'accepted', 'accepted_at' => now()]);

        $shipmentId = null;
        if ($data['create_shipment'] ?? false) {
            $shipmentId = $this->commands->dispatch(new CreateShipmentCommand(
                clientId: $quote->party_id,
                branchId: $data['branch_id'],
                companyId: $quote->company_id,
                agentId: $data['agent_id'],
                direction: $quote->direction,
                mode: $quote->mode,
                incotermCode: $quote->incoterm_code,
                originLocode: $quote->origin_locode,
                destinationLocode: $quote->destination_locode,
                quoteId: $quote->id,
            ));
        }

        return response()->json(['quote' => $quote->fresh(), 'shipment_id' => $shipmentId]);
    }

    /** POST /v1/quotes/{id}/reject */
    public function reject(Request $request, string $quoteId): JsonResponse
    {
        $quote = QuoteModel::where('status', 'sent')->findOrFail($quoteId);
        $quote->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null,
        ]);

        return response()->json($quote->fresh());
    }
}
