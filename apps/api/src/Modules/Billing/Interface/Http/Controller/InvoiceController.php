<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Interface\Http\Controller;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Silaris\Modules\Billing\Application\Fne\CertifyInvoice;
use Silaris\Modules\Billing\Domain\Accounting\AccountingLedger;
use Silaris\Modules\Billing\Domain\Event\InvoiceValidated;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\TaxRateModel;
use Silaris\Modules\Shared\Domain\Service\QrSvg;
use Silaris\Modules\Shared\Infrastructure\Events\DomainEventPublisher;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Tenancy\Application\Service\BrandingResolver;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::in(['proforma', 'invoice', 'credit_note'])],
            'status' => ['sometimes', Rule::in(['draft', 'validated'])],
            'accounting_export_status' => ['sometimes', Rule::in(['none', 'pending', 'exported', 'failed'])],
            'payment_status' => ['sometimes', Rule::in(['none', 'unpaid', 'partial', 'paid'])],
            'party_id' => ['sometimes', 'uuid'],
            'shipment_id' => ['sometimes', 'uuid'],
        ]);

        return response()->json(
            InvoiceModel::with(['party:id,name,code', 'shipment:id,reference'])
                ->when($validated['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
                ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($validated['accounting_export_status'] ?? null, fn ($q, $s) => $q->where('accounting_export_status', $s))
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

    /**
     * POST /v1/invoices/from-quote/{quoteId} — déverse une cotation dans un
     * brouillon de facture.
     *
     * Seule une cotation acceptée par le client se facture : c'est elle qui fait
     * accord sur le prix. Ses lignes deviennent celles de la facture, à
     * l'identique — la facture ne réinvente pas le devis, elle le transcrit.
     * Le brouillon reste modifiable et n'engage rien avant sa validation.
     */
    public function fromQuote(string $quoteId): JsonResponse
    {
        // Lecture inter-module par le query builder, pas par le modèle du module
        // Pricing : la facturation ne dépend pas des entités de la cotation.
        $quote = DB::table('quotes')->where('id', $quoteId)->where('status', 'accepted')
            ->first(['id', 'company_id', 'party_id', 'currency_code', 'total_amount']);

        abort_if($quote === null, 404, 'Cotation introuvable ou non acceptée : seule une cotation validée par le client se facture.');

        $lines = DB::table('quote_lines')->where('quote_id', $quote->id)->orderBy('position')
            ->get(['service_code', 'description', 'quantity', 'unit', 'unit_price']);

        // Le dossier ouvert sur la cotation, s'il existe : la facture s'y
        // rattache pour que règlement et recouvrement retombent sur le dossier.
        $shipmentId = DB::table('shipments')->where('quote_id', $quote->id)->value('id');

        $invoice = DB::transaction(function () use ($quote, $lines, $shipmentId) {
            $invoice = InvoiceModel::create([
                'company_id' => $quote->company_id,
                'type' => 'invoice',
                'party_id' => $quote->party_id,
                'shipment_id' => $shipmentId,
                'quote_id' => $quote->id,
                'currency_code' => $quote->currency_code,
                'status' => 'draft',
                'total_excl_tax' => $quote->total_amount,
                'total_tax' => 0,
                'total_incl_tax' => $quote->total_amount,
            ]);

            foreach ($lines->values() as $i => $line) {
                $invoice->lines()->create([
                    'position' => $i + 1,
                    'service_code' => $line->service_code,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->unit_price,
                    'tax_rate_id' => null,
                ]);
            }

            return $invoice;
        });

        return response()->json($invoice->fresh('lines'), 201);
    }

    /**
     * POST /v1/invoices/{id}/fne-certify — fait certifier la facture par la DGI.
     *
     * Le taux de change n'est exigé que pour une facture en devise étrangère
     * (B2F) ; ailleurs il est ignoré. La facture doit être validée : on ne
     * certifie pas un brouillon.
     */
    public function certifyFne(Request $request, string $invoiceId, CertifyInvoice $certifier): JsonResponse
    {
        $invoice = InvoiceModel::findOrFail($invoiceId);
        $rate = $request->validate([
            'foreign_currency_rate' => ['nullable', 'numeric', 'gt:0'],
        ])['foreign_currency_rate'] ?? null;

        // Le vendeur porté sur la facture normalisée est celui qui la certifie.
        $user = $request->user();
        $sellerName = $user !== null ? trim("{$user->first_name} {$user->last_name}") : null;

        return response()->json($certifier->certify($invoice, $rate !== null ? (float) $rate : null, $sellerName ?: null));
    }

    /** GET /v1/tax-rates — barème actif, pour le choix de la TVA à la ligne. */
    public function taxRates(): JsonResponse
    {
        return response()->json(
            TaxRateModel::where('is_active', true)->orderBy('rate_percent')->get(['id', 'name', 'rate_percent']),
        );
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
    public function validateInvoice(Request $request, string $invoiceId, AccountingLedger $ledger): JsonResponse
    {
        $invoice = InvoiceModel::where('status', 'draft')->findOrFail($invoiceId);

        DB::transaction(function () use ($invoice, $request, $ledger): void {
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
                // Un débouché comptable branché → export à venir ; sinon rien
                // n'est attendu d'un système extérieur.
                'accounting_export_status' => $ledger->isConfigured() ? 'pending' : 'none',
            ]);

            // Notification client « facture disponible » via l'outbox (même transaction).
            app(DomainEventPublisher::class)->publish(new InvoiceValidated(
                invoiceId: $invoice->id,
                number: $number,
                total: (string) $invoice->total_incl_tax,
                currency: (string) $invoice->currency_code,
                clientId: (string) $invoice->party_id,
                shipmentId: $invoice->shipment_id,
                at: new \DateTimeImmutable,
            ));
        });

        // Report vers la comptabilité configurée, après commit. Le connecteur —
        // Odoo ou un autre — gère ses reprises ; sans connecteur, rien ne part.
        $ledger->queueExport($this->tenant->id(), $invoice->id);

        return response()->json($invoice->fresh('lines'));
    }

    /** POST /v1/invoices/{id}/credit-note — avoir depuis une facture validée. */
    public function creditNote(Request $request, string $invoiceId): JsonResponse
    {
        $original = InvoiceModel::where('type', 'invoice')
            ->where('status', 'validated')
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

    /** GET /v1/invoices/{id}/pdf — document imprimable (facture, proforma ou avoir). */
    public function pdf(string $invoiceId): Response
    {
        $invoice = InvoiceModel::with(['lines', 'party.contacts', 'shipment'])->findOrFail($invoiceId);
        $company = CompanyModel::findOrFail($invoice->company_id);

        // Facture d'origine d'un avoir : la facture normalisée d'avoir rappelle
        // le numéro fiscal de la facture qu'elle corrige.
        $originalFne = $invoice->original_invoice_id !== null
            ? InvoiceModel::where('id', $invoice->original_invoice_id)->value('fne_reference')
            : null;

        $prefix = match ($invoice->type) {
            'credit_note' => 'avoir',
            'proforma' => 'proforma',
            default => 'facture',
        };
        $name = $prefix.'-'.($invoice->number ?? 'brouillon-'.substr($invoice->id, 0, 8)).'.pdf';

        // QR de certification : la douane le scanne pour vérifier la facture
        // auprès de la DGI. Il n'existe qu'une fois la facture certifiée.
        $fneQr = ($invoice->fne_token ?? '') !== '' ? QrSvg::dataUri((string) $invoice->fne_token) : null;

        // Taux par ligne, pour la colonne Taxes de la facture normalisée —
        // résolus en une requête plutôt qu'une par ligne.
        $taxRates = TaxRateModel::whereIn('id', $invoice->lines->pluck('tax_rate_id')->filter()->all())
            ->pluck('rate_percent', 'id');

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'logo' => app(BrandingResolver::class)->logoDataUri($company),
            'fneQr' => $fneQr,
            'originalFne' => $originalFne,
            'taxRates' => $taxRates,
        ])->download($name);
    }
}
