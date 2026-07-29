<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Silaris\Modules\Billing\Application\Service\PaymentRecorder;
use Silaris\Modules\Billing\Domain\Service\ReceivableBalance;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\PaymentModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;

class PaymentController
{
    private const METHODS = ['cash', 'transfer', 'cheque', 'mobile_money', 'card', 'compensation'];

    public function __construct(private readonly PaymentRecorder $recorder) {}

    /** GET /v1/payments — journal des encaissements. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'party_id' => ['sometimes', 'uuid'],
            'invoice_id' => ['sometimes', 'uuid'],
            'method' => ['sometimes', Rule::in(self::METHODS)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'include_cancelled' => ['sometimes', 'boolean'],
        ]);

        return response()->json(
            PaymentModel::with(['party:id,code,name', 'allocations'])
                ->when($filters['party_id'] ?? null, fn ($q, $p) => $q->where('party_id', $p))
                ->when($filters['method'] ?? null, fn ($q, $m) => $q->where('method', $m))
                ->when($filters['from'] ?? null, fn ($q, $d) => $q->where('received_on', '>=', $d))
                ->when($filters['to'] ?? null, fn ($q, $d) => $q->where('received_on', '<=', $d))
                ->when(
                    $filters['invoice_id'] ?? null,
                    fn ($q, $i) => $q->whereHas('allocations', fn ($a) => $a->where('invoice_id', $i)),
                )
                // Un règlement annulé reste consultable, mais ne pollue pas le
                // journal courant : il faut le demander.
                ->when(! ($filters['include_cancelled'] ?? false), fn ($q) => $q->whereNull('cancelled_at'))
                ->orderByDesc('received_on')
                ->orderByDesc('created_at')
                ->cursorPaginate(50),
        );
    }

    public function show(string $paymentId): JsonResponse
    {
        return response()->json(
            PaymentModel::with(['party:id,code,name', 'allocations.invoice:id,number,total_incl_tax,currency_code'])
                ->findOrFail($paymentId),
        );
    }

    /**
     * POST /v1/payments — enregistre un encaissement.
     *
     * Sans imputations explicites, le règlement solde les factures de la plus
     * ancienne à la plus récente : c'est l'usage, et c'est ce qui contient
     * l'ancienneté de la créance.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'method' => ['required', Rule::in(self::METHODS)],
            'method_reference' => ['nullable', 'string', 'max:120'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'allocations' => ['sometimes', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['required', 'uuid', 'exists:invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $payment = $this->recorder->record(
            $data,
            isset($data['allocations']) ? array_values($data['allocations']) : null,
            $request->user()?->id,
        );

        return response()->json($payment->load('allocations'), 201);
    }

    /** POST /v1/payments/{id}/cancel — chèque impayé, saisie erronée. */
    public function cancel(Request $request, string $paymentId): JsonResponse
    {
        $payment = PaymentModel::whereNull('cancelled_at')->findOrFail($paymentId);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:300']])['reason'];

        return response()->json($this->recorder->cancel($payment, $reason, $request->user()?->id));
    }

    /** GET /v1/receivables/{partyId} — ce qu'un client doit encore, facture par facture. */
    public function outstanding(string $partyId): JsonResponse
    {
        $party = PartyModel::findOrFail($partyId);
        $invoices = $this->recorder->outstandingOf($partyId);

        return response()->json([
            'party' => ['id' => $party->id, 'code' => $party->code, 'name' => $party->name],
            'invoices' => $invoices,
            'total' => round(array_sum(array_column($invoices, 'outstanding')), 2),
        ]);
    }

    /**
     * GET /v1/receivables — balance âgée.
     *
     * Le total dû se lit partout ; c'est son ancienneté qui décide s'il faut
     * relancer, bloquer un dossier ou provisionner.
     */
    public function aged(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'party_id' => ['sometimes', 'uuid'],
            'as_of' => ['sometimes', 'date'],
        ]);

        $asOf = new \DateTimeImmutable($filters['as_of'] ?? 'today');

        $byParty = $this->recorder->outstandingByParty($filters['party_id'] ?? null);
        $parties = PartyModel::whereIn('id', array_keys($byParty))->get(['id', 'code', 'name']);

        $rows = [];
        foreach ($parties as $party) {
            $aged = ReceivableBalance::aged(
                array_map(static fn (array $i): array => [
                    'due_date' => new \DateTimeImmutable($i['due_date']),
                    'outstanding' => $i['outstanding'],
                ], $byParty[(string) $party->id]),
                $asOf,
            );

            $rows[] = ['party' => ['id' => $party->id, 'code' => $party->code, 'name' => $party->name]] + $aged;
        }

        // Le plus gros retard d'abord : c'est l'ordre dans lequel on recouvre.
        usort($rows, static fn (array $a, array $b): int => $b['over_90'] <=> $a['over_90'] ?: $b['total'] <=> $a['total']);

        return response()->json([
            'as_of' => $asOf->format('Y-m-d'),
            'rows' => $rows,
            'totals' => [
                'current' => round(array_sum(array_column($rows, 'current')), 2),
                '1_30' => round(array_sum(array_column($rows, '1_30')), 2),
                '31_60' => round(array_sum(array_column($rows, '31_60')), 2),
                '61_90' => round(array_sum(array_column($rows, '61_90')), 2),
                'over_90' => round(array_sum(array_column($rows, 'over_90')), 2),
                'total' => round(array_sum(array_column($rows, 'total')), 2),
            ],
        ]);
    }
}
