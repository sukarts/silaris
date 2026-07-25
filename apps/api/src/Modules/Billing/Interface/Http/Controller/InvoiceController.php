<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\TaxRateModel;
use Silaris\Modules\OdooSync\Application\Job\PushInvoiceToOdoo;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

class InvoiceController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['proforma', 'invoice', 'credit_note'])],
            'status' => ['sometimes', Rule::in(['draft', 'validated', 'synced', 'sync_failed'])],
            'payment_status' => ['sometimes', Rule::in(['none', 'unpaid', 'partial', 'paid'])],
            'party_id' => ['sometimes', 'uuid'],
            'shipment_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(
            InvoiceModel::with(['party:id,name,code', 'shipment:id,reference'])
                ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($validated['payment_status'] ?? null, fn ($q, $p) => $q->where('payment_status', $p))
                ->when($validated['party_id'] ?? null, fn ($q, $p) => $q->where('party_id', $p))
                ->when($validated['shipment_id'] ?? null, fn ($q, $s) => $q->where('shipment_id', $s))
                ->orderByDesc('created_at')
                ->cursorPaginate(25),
        );
    }

    public function show(string $invoiceId): JsonResponse
    {
        return response()->json(
            InvoiceModel::with(['lines', 'party:id,name,code', 'shipment:id,reference', 'originalInvoice:id,number'])
                ->findOrFail($invoiceId),
        );
    }

    /** POST /v1/invoices — création en BROUILLON uniquement. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $invoice = DB::transaction(function () use ($data) {
            $lines = $data['lines'];
            unset($data['lines']);
            $invoice = InvoiceModel::create([...$data, 'status' => 'draft', ...$this->computeTotals($lines)]);
            foreach ($lines as $i => $line) {
                $invoice->lines()->create([...$line, 'position' => $i + 1]);
            }

            return $invoice;
        });

        return response()->json($invoice->fresh('lines'), 201);
    }

    /** PATCH — brouillon uniquement (le trigger pg protège de toute façon). */
    public function update(Request $request, string $invoiceId): JsonResponse
    {
        $invoice = InvoiceModel::where('status', 'draft')->findOrFail($invoiceId);
        $data = $this->validatePayload($request);

        DB::transaction(function () use ($invoice, $data): void {
            $lines = $data['lines'];
            unset($data['lines']);
            $invoice->lines()->delete();
            foreach ($lines as $i => $line) {
                $invoice->lines()->create([...$line, 'position' => $i + 1]);
            }
            $invoice->update([...$data, ...$this->computeTotals($lines)]);
        });

        return response()->json($invoice->fresh('lines'));
    }

    /**
     * POST /v1/invoices/{id}/validate — attribue le numéro légal (séquence sans trou
     * par société+type) et fige la facture. La sync Odoo suit (job outbox, Étape 20).
     */
    public function validateInvoice(Request $request, string $invoiceId): JsonResponse
    {
        $invoice = InvoiceModel::where('status', 'draft')->findOrFail($invoiceId);

        DB::transaction(function () use ($invoice, $request): void {
            $company = CompanyModel::findOrFail($invoice->company_id);
            $format = $company->invoice_settings['number_format'] ?? 'F-{YEAR}-{SEQ:4}';

            $sequence = DB::selectOne('SELECT next_sequence(?, ?) AS value', [
                $this->tenant->id(), "invoice:{$invoice->company_id}:{$invoice->type}",
            ])->value;

            $number = str_replace(
                ['{YEAR}', '{SEQ:4}', '{SEQ:5}', '{SEQ:6}'],
                [date('Y'), sprintf('%04d', $sequence), sprintf('%05d', $sequence), sprintf('%06d', $sequence)],
                $invoice->type === 'credit_note' ? 'A-'.substr($format, 2) : $format,
            );

            $invoice->update([
                'status' => 'validated',
                'number' => $number,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->addDays($invoice->party->payment_terms_days ?? 30)->toDateString(),
                'payment_status' => 'unpaid',
                'validated_at' => now(),
                'validated_by' => $request->user()?->id,
            ]);
        });

        // Sync Odoo asynchrone après commit — mode dégradé géré par le job (backoff).
        PushInvoiceToOdoo::dispatch($this->tenant->id(), $invoice->id)->afterCommit();

        return response()->json($invoice->fresh('lines'));
    }

    /** POST /v1/invoices/{id}/credit-note — avoir depuis une facture validée. */
    public function creditNote(Request $request, string $invoiceId): JsonResponse
    {
        $original = InvoiceModel::where('type', 'invoice')
            ->whereIn('status', ['validated', 'synced'])
            ->with('lines')
            ->findOrFail($invoiceId);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $creditNote = DB::transaction(function () use ($original, $data) {
            $creditNote = InvoiceModel::create([
                'company_id' => $original->company_id,
                'type' => 'credit_note',
                'party_id' => $original->party_id,
                'shipment_id' => $original->shipment_id,
                'original_invoice_id' => $original->id,
                'status' => 'draft',
                'currency_code' => $original->currency_code,
                'credit_reason' => $data['reason'],
                'total_excl_tax' => $original->total_excl_tax,
                'total_tax' => $original->total_tax,
                'total_incl_tax' => $original->total_incl_tax,
            ]);
            foreach ($original->lines as $line) {
                $creditNote->lines()->create($line->only(['position', 'service_code', 'description', 'quantity', 'unit', 'unit_price', 'tax_rate_id']));
            }

            return $creditNote;
        });

        return response()->json($creditNote->fresh('lines'), 201);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'type' => ['required', Rule::in(['proforma', 'invoice'])],
            'party_id' => ['required', 'uuid', 'exists:parties,id'],
            'shipment_id' => ['nullable', 'uuid', 'exists:shipments,id'],
            'quote_id' => ['nullable', 'uuid', 'exists:quotes,id'],
            'currency_code' => ['required', 'size:3', 'exists:currencies,code'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.service_code' => ['required', 'string', 'max:32'],
            'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'lines.*.unit' => ['required', Rule::in(['container', 'kg', 'm3', 'wm', 'flat', 'percent', 'unit'])],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.tax_rate_id' => ['nullable', 'uuid', 'exists:tax_rates,id'],
        ]);
    }

    /** @return array{total_excl_tax: float, total_tax: float, total_incl_tax: float} */
    private function computeTotals(array $lines): array
    {
        $rates = TaxRateModel::whereIn('id', array_filter(array_column($lines, 'tax_rate_id')))->pluck('rate_percent', 'id');

        $excl = 0.0;
        $tax = 0.0;
        foreach ($lines as $line) {
            $lineTotal = round($line['quantity'] * $line['unit_price'], 2);
            $excl += $lineTotal;
            if (! empty($line['tax_rate_id'])) {
                $tax += round($lineTotal * (float) $rates[$line['tax_rate_id']] / 100, 2);
            }
        }

        return ['total_excl_tax' => $excl, 'total_tax' => $tax, 'total_incl_tax' => $excl + $tax];
    }
}
