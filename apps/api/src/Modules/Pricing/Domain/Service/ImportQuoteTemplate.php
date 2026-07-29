<?php

declare(strict_types=1);

namespace Silaris\Modules\Pricing\Domain\Service;

/**
 * Trame d'une offre de transit maritime à l'import.
 *
 * Les libellés reprennent l'offre type du transitaire, dans son ordre. Les
 * proposer d'emblée évite qu'un poste soit oublié à la saisie — un débours
 * omis à la cotation se facture ensuite au client sans avoir été annoncé, ou
 * reste à la charge du transitaire.
 *
 * Deux familles, deux sous-totaux : ce qui part à la douane, que le client
 * paie sans marge, et ce que le transitaire facture pour son travail.
 */
final class ImportQuoteTemplate
{
    /** Débours douane — calculables depuis la position tarifaire et la valeur CAF. */
    public const CUSTOMS = [
        ['code' => 'DD', 'label' => 'Droit de douane'],
        ['code' => 'RSTA', 'label' => 'RSTA'],
        ['code' => 'PCS', 'label' => 'PCS'],
        ['code' => 'PUA', 'label' => 'PUA'],
        ['code' => 'PCC', 'label' => 'PCC'],
        ['code' => 'RPI', 'label' => 'RPI'],
        ['code' => 'TVA', 'label' => 'TVA'],
        ['code' => 'TS_SYDAM', 'label' => 'TS + Sydam'],
    ];

    /** Débours divers — négociés ou constatés, saisis par l'exploitant. */
    public const OTHER = [
        ['code' => 'OUVERTURE', 'label' => 'Ouverture'],
        ['code' => 'FDI_RFCV', 'label' => 'FDI/RFCV'],
        ['code' => 'ASSURANCE', 'label' => 'Assurance'],
        ['code' => 'TIRAGE', 'label' => 'Tirage'],
        ['code' => 'PASSAGE', 'label' => 'Passage'],
        ['code' => 'AGIO', 'label' => 'Agio/Gestion crédit'],
        ['code' => 'AMENDE_BSC', 'label' => 'Amende BSC'],
        ['code' => 'VISITE', 'label' => 'Visite'],
        ['code' => 'ACCONAGE', 'label' => 'Acconage'],
        ['code' => 'CAUTION', 'label' => 'Caution'],
        ['code' => 'ECHANGE_BL', 'label' => 'Echange BL'],
        ['code' => 'LIVRAISON', 'label' => 'Livraison'],
        ['code' => 'COMMISSION', 'label' => 'Commission de facilitation'],
        ['code' => 'PRESTATIONS', 'label' => 'Prestations'],
    ];

    /**
     * Trame complète, prête à remplir. Les montants restent à zéro : la trame
     * propose les postes, elle ne préjuge pas des prix.
     *
     * @return list<array{service_code: string, description: string, category: string, quantity: int, unit: string, unit_price: int}>
     */
    public static function lines(): array
    {
        $build = static fn (array $entry, string $category): array => [
            'service_code' => $entry['code'],
            'description' => $entry['label'],
            'category' => $category,
            'quantity' => 1,
            'unit' => 'flat',
            'unit_price' => 0,
        ];

        return [
            ...array_map(static fn ($entry) => $build($entry, 'customs'), self::CUSTOMS),
            ...array_map(static fn ($entry) => $build($entry, 'other'), self::OTHER),
        ];
    }
}
