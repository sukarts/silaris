<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Application\Fne;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Billing\Domain\Fne\FneCertificationFailed;
use Silaris\Modules\Billing\Domain\Fne\FneTemplate;
use Silaris\Modules\Billing\Infrastructure\Fne\FneClient;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

/**
 * Fait certifier une facture par la DGI et inscrit son sceau sur la facture.
 *
 * Tout se joue avant l'appel : une facture non validée, une société non
 * enrôlée, un NCC manquant en B2B se refusent ici, pour ne pas consommer un
 * sticker DGI sur un envoi voué au rejet. Une fois la DGI ayant répondu, le
 * numéro fiscal et le jeton de vérification sont posés sur la facture — ils y
 * deviennent la preuve de conformité, et le QR du document imprimé.
 */
final class CertifyInvoice
{
    public function __construct(
        private readonly FneInvoiceTranslator $translator,
        private readonly FneClient $client,
    ) {}

    public function certify(InvoiceModel $invoice, ?float $foreignCurrencyRate = null): InvoiceModel
    {
        if ($invoice->status === 'draft' || $invoice->number === null) {
            throw FneCertificationFailed::invoiceNotValidated();
        }
        if ($invoice->fne_reference !== null) {
            throw FneCertificationFailed::alreadyCertified((string) $invoice->fne_reference);
        }

        $company = CompanyModel::findOrFail($invoice->company_id);
        $settings = $company->fne_settings ?? [];

        if (! ($settings['enabled'] ?? false) || ($company->fne_api_key ?? '') === '' || ($settings['ncc'] ?? '') === '') {
            throw FneCertificationFailed::notConfigured();
        }

        $client = PartyModel::with('contacts')->findOrFail($invoice->party_id);
        $template = FneTemplate::decide((string) $invoice->currency_code, $client->ncc);

        if ($template->requiresClientNcc() && ($client->ncc ?? '') === '') {
            throw FneCertificationFailed::clientNccRequired();
        }
        if ($template->requiresForeignCurrency() && ($foreignCurrencyRate === null || $foreignCurrencyRate <= 0)) {
            throw FneCertificationFailed::foreignRateRequired();
        }

        $invoice->loadMissing('lines');
        $payload = $this->translator->toPayload($invoice, $company, $client, $template, $foreignCurrencyRate);

        // L'appel réseau reste hors transaction : il peut être long, et rien en
        // base ne doit être verrouillé pendant qu'on attend la DGI.
        $result = $this->client->sign((string) $company->fne_api_key, $payload);

        DB::transaction(function () use ($invoice, $result, $template): void {
            $invoice->update([
                'fne_reference' => $result['reference'],
                'fne_token' => $result['token'],
                'fne_balance_sticker' => $result['balance_sticker'],
                'fne_template' => $template->value,
                'fne_certified_at' => now(),
            ]);
        });

        return $invoice->fresh();
    }
}
