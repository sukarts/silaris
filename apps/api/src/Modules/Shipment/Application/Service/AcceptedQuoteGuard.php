<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shipment\Domain\Exception\QuoteNotAccepted;

/**
 * Un dossier n'est ouvert que sur une cotation acceptée par le client.
 *
 * La règle protège des deux côtés : le transitaire n'engage pas de frais sans
 * accord écrit, et le client ne découvre pas une facture qu'il n'a pas validée.
 *
 * Elle porte aussi les conditions du dossier. Sans quoi une cotation acceptée
 * pour un FCL Shanghai–Abidjan pourrait ouvrir un dossier aérien : le dossier
 * reprend donc mode, sens, incoterm et ports de la cotation, qui font foi.
 */
final readonly class AcceptedQuoteGuard
{
    /**
     * @return array{mode: string, direction: string, incoterm_code: string, origin_locode: string, destination_locode: string, currency_code: string, total_amount: string}
     */
    public function termsOf(?string $quoteId, string $clientId): array
    {
        if ($quoteId === null) {
            throw QuoteNotAccepted::missing();
        }

        $quote = DB::table('quotes')->where('id', $quoteId)->first([
            'id', 'number', 'status', 'party_id', 'mode', 'direction', 'incoterm_code',
            'origin_locode', 'destination_locode', 'currency_code', 'total_amount',
        ]);

        if ($quote === null) {
            throw QuoteNotAccepted::missing();
        }

        if ($quote->party_id !== $clientId) {
            throw QuoteNotAccepted::otherClient($quote->number);
        }

        if ($quote->status !== 'accepted') {
            throw QuoteNotAccepted::notAcceptedYet($quote->number, $quote->status);
        }

        // Une cotation n'ouvre qu'un dossier : sans cette garde, un même accord
        // client couvrirait plusieurs expéditions facturées séparément.
        $existing = DB::table('shipments')->where('quote_id', $quoteId)->value('reference');
        if ($existing !== null) {
            throw QuoteNotAccepted::alreadyUsed($quote->number, (string) $existing);
        }

        return [
            'mode' => (string) $quote->mode,
            'direction' => (string) $quote->direction,
            'incoterm_code' => (string) $quote->incoterm_code,
            'origin_locode' => (string) $quote->origin_locode,
            'destination_locode' => (string) $quote->destination_locode,
            'currency_code' => (string) $quote->currency_code,
            'total_amount' => (string) $quote->total_amount,
        ];
    }
}
