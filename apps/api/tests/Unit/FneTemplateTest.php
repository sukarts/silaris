<?php

declare(strict_types=1);

use Silaris\Modules\Billing\Domain\Fne\FneTaxCode;
use Silaris\Modules\Billing\Domain\Fne\FneTemplate;

it('choisit le gabarit selon la devise et le NCC', function (string $currency, ?string $ncc, FneTemplate $expected): void {
    expect(FneTemplate::decide($currency, $ncc))->toBe($expected);
})->with([
    'client identifié en CFA → B2B' => ['XOF', '1234567A', FneTemplate::B2B],
    'particulier en CFA → B2C' => ['XOF', null, FneTemplate::B2C],
    'NCC vide → B2C' => ['XOF', '  ', FneTemplate::B2C],
    'devise étrangère → B2F' => ['EUR', '1234567A', FneTemplate::B2F],
    'dollar → B2F' => ['USD', null, FneTemplate::B2F],
]);

it('n exige le NCC client qu en B2B', function (): void {
    expect(FneTemplate::B2B->requiresClientNcc())->toBeTrue()
        ->and(FneTemplate::B2C->requiresClientNcc())->toBeFalse()
        ->and(FneTemplate::B2F->requiresClientNcc())->toBeFalse();
});

it('n exige devise et taux qu en B2F', function (): void {
    expect(FneTemplate::B2F->requiresForeignCurrency())->toBeTrue()
        ->and(FneTemplate::B2B->requiresForeignCurrency())->toBeFalse();
});

it('mappe le taux de TVA vers le code DGI, TVAD pour le non taxé', function (): void {
    // Une facture certifiée observée sur la plateforme porte TVAD (0) sur les
    // lignes hors champ : la DGI attend un code par ligne, jamais le vide.
    expect(FneTaxCode::forRate(18.0))->toBe(['TVA'])
        ->and(FneTaxCode::forRate(9.0))->toBe(['TVAB'])
        // Un débours douane, hors champ de la TVA par la loi : exonération légale.
        ->and(FneTaxCode::forRate(0.0))->toBe(['TVAD'])
        ->and(FneTaxCode::forRate(null))->toBe(['TVAD']);
});
