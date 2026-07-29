<?php

declare(strict_types=1);

use Silaris\Modules\Billing\Domain\Service\ReceivableBalance;

it('ne rend jamais une créance négative', function (): void {
    // Un trop-perçu est un fait de caisse, pas une créance négative : il se
    // solde par un avoir, il ne vient pas diminuer le total dû par ailleurs.
    expect(ReceivableBalance::outstanding(100_000, 120_000))->toBe(0.0)
        ->and(ReceivableBalance::outstanding(100_000, 40_000))->toBe(60_000.0);
});

it('déduit l état de paiement, y compris au centime près', function (): void {
    expect(ReceivableBalance::status(100_000, 0))->toBe('unpaid')
        ->and(ReceivableBalance::status(100_000, 40_000))->toBe('partial')
        ->and(ReceivableBalance::status(100_000, 100_000))->toBe('paid')
        // Les décimaux relus de la base laissent des écarts infimes : une
        // égalité stricte afficherait « partiel » sur une facture soldée.
        ->and(ReceivableBalance::status(100_000, 99_999.999))->toBe('paid')
        ->and(ReceivableBalance::status(100_000, 99_999.50))->toBe('partial');
});

it('classe une créance par ancienneté, l échéance du jour n étant pas du retard', function (): void {
    $asOf = new DateTimeImmutable('2026-07-29');

    expect(ReceivableBalance::bucket(new DateTimeImmutable('2026-08-15'), $asOf))->toBe('current')
        ->and(ReceivableBalance::bucket(new DateTimeImmutable('2026-07-29'), $asOf))->toBe('current')
        ->and(ReceivableBalance::bucket(new DateTimeImmutable('2026-07-28'), $asOf))->toBe('1_30')
        ->and(ReceivableBalance::bucket(new DateTimeImmutable('2026-06-29'), $asOf))->toBe('1_30')
        ->and(ReceivableBalance::bucket(new DateTimeImmutable('2026-06-28'), $asOf))->toBe('31_60')
        ->and(ReceivableBalance::bucket(new DateTimeImmutable('2026-05-29'), $asOf))->toBe('61_90')
        ->and(ReceivableBalance::bucket(new DateTimeImmutable('2026-04-01'), $asOf))->toBe('over_90');
});

it('répartit les créances par tranche et totalise', function (): void {
    $aged = ReceivableBalance::aged([
        ['due_date' => new DateTimeImmutable('2026-08-30'), 'outstanding' => 500_000.0],
        ['due_date' => new DateTimeImmutable('2026-07-10'), 'outstanding' => 250_000.0],
        ['due_date' => new DateTimeImmutable('2026-03-01'), 'outstanding' => 1_200_000.0],
    ], new DateTimeImmutable('2026-07-29'));

    expect($aged['current'])->toBe(500_000.0)
        ->and($aged['1_30'])->toBe(250_000.0)
        ->and($aged['over_90'])->toBe(1_200_000.0)
        ->and($aged['total'])->toBe(1_950_000.0);
});

it('impute au plus ancien et rend le reliquat non placé', function (): void {
    $result = ReceivableBalance::allocateOldestFirst(700_000, [
        ['invoice_id' => 'a', 'outstanding' => 300_000.0],
        ['invoice_id' => 'b', 'outstanding' => 250_000.0],
        ['invoice_id' => 'c', 'outstanding' => 400_000.0],
    ]);

    expect($result['allocations'])->toBe([
        ['invoice_id' => 'a', 'amount' => 300_000.0],
        ['invoice_id' => 'b', 'amount' => 250_000.0],
        ['invoice_id' => 'c', 'amount' => 150_000.0],
    ])->and($result['unallocated'])->toBe(0.0);
});

it('laisse en acompte ce qui dépasse les créances', function (): void {
    // Un client peut payer d'avance : le surplus reste porté par le règlement
    // au lieu d'être imputé de force sur une facture qui n'existe pas encore.
    $result = ReceivableBalance::allocateOldestFirst(500_000, [
        ['invoice_id' => 'a', 'outstanding' => 300_000.0],
    ]);

    expect($result['allocations'])->toBe([['invoice_id' => 'a', 'amount' => 300_000.0]])
        ->and($result['unallocated'])->toBe(200_000.0);
});

it('n impute rien quand plus rien n est dû', function (): void {
    expect(ReceivableBalance::allocateOldestFirst(500_000, []))
        ->toBe(['allocations' => [], 'unallocated' => 500_000.0]);
});
