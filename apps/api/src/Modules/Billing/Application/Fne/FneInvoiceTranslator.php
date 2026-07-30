<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Application\Fne;

use Illuminate\Support\Collection;
use Silaris\Modules\Billing\Domain\Fne\FneTaxCode;
use Silaris\Modules\Billing\Domain\Fne\FneTemplate;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\TaxRateModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

/**
 * Traduit une facture SILARIS dans la charge utile attendue par la DGI.
 *
 * La DGI n'accepte pas un montant TTC calculé chez nous : elle reçoit les
 * lignes hors taxe et leurs codes de taxe, puis recalcule elle-même. On lui
 * transmet donc le prix unitaire hors taxe et le code — TVA, TVAB — non le
 * total. C'est aussi ce qui permet à son montant de faire foi contre le nôtre.
 */
final class FneInvoiceTranslator
{
    /**
     * @return array<string, mixed>
     */
    public function toPayload(
        InvoiceModel $invoice,
        CompanyModel $company,
        PartyModel $client,
        FneTemplate $template,
        ?float $foreignCurrencyRate,
        ?string $sellerName = null,
    ): array {
        $settings = $company->fne_settings ?? [];

        // Coordonnées du client : elles vivent sur son contact principal, pas
        // sur la fiche tiers elle-même.
        $contact = $client->contacts->firstWhere('is_primary', true) ?? $client->contacts->first();

        // Taux de TVA par ligne, résolus en une requête plutôt qu'une par ligne.
        $rates = TaxRateModel::whereIn('id', $invoice->lines->pluck('tax_rate_id')->filter()->all())
            ->pluck('rate_percent', 'id');

        $payload = [
            'invoiceType' => 'sale',
            'paymentMethod' => 'deferred', // Le transit se règle à terme, pas au comptant.
            'template' => $template->value,
            'pointOfSale' => (string) ($settings['point_of_sale'] ?? ''),
            'establishment' => (string) ($settings['establishment'] ?? $company->legal_name),
            'clientCompanyName' => $client->name,
            'clientPhone' => (string) ($contact->phone ?? ''),
            'clientEmail' => (string) ($contact->email ?? ''),
            'isRne' => false,
            'items' => $invoice->lines->sortBy('position')->values()->map(
                fn ($line) => $this->line($line, $rates)
            )->all(),
        ];

        if ($sellerName !== null && $sellerName !== '') {
            $payload['clientSellerName'] = $sellerName;
        }

        if ($template->requiresClientNcc()) {
            $payload['clientNcc'] = (string) $client->ncc;
        }

        if ($template->requiresForeignCurrency()) {
            // La devise étrangère est celle de la facture ; le taux ramène au CFA.
            $payload['foreignCurrency'] = $invoice->currency_code;
            $payload['foreignCurrencyRate'] = $foreignCurrencyRate ?? 0.0;
        }

        return $payload;
    }

    /**
     * @param  Collection<int|string, mixed>  $rates
     * @return array<string, mixed>
     */
    private function line(mixed $line, $rates): array
    {
        $rate = $line->tax_rate_id !== null ? (float) ($rates[$line->tax_rate_id] ?? 0) : null;

        $item = [
            'description' => (string) $line->description,
            'quantity' => (float) $line->quantity,
            'amount' => (float) $line->unit_price, // Prix unitaire hors taxe.
            'taxes' => FneTaxCode::forRate($rate),
            'reference' => (string) $line->service_code,
        ];

        // La DGI n'admet pas nos unités internes ; le forfait est son défaut.
        if ($line->unit !== 'flat') {
            $item['measurementUnit'] = (string) $line->unit;
        }

        return $item;
    }
}
