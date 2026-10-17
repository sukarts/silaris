<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('ne montre jamais le prix d\'achat au client sur le portail', function (): void {
    $ids = seedCore();
    $quoteId = seedAcceptedQuote($ids, null, ['status' => 'sent', 'accepted_at' => null, 'total_buy_amount' => 800_000]);
    DB::table('quote_lines')->insert([
        'id' => (string) Str::uuid7(), 'quote_id' => $quoteId, 'position' => 1, 'service_code' => 'FRT',
        'description' => 'Fret maritime', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 1_250_000,
        'buy_price' => 800_000, 'currency_code' => 'XOF',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $token = portalTokenFor($ids['portal']);

    $quote = $this->withToken($token)->getJson('/api/v1/portal/quotes')->assertOk()->json('data.0');
    expect($quote)->toHaveKey('total_amount')->not->toHaveKey('total_buy_amount')
        ->and($quote['lines'][0])->toHaveKey('unit_price')->not->toHaveKey('buy_price');

    freshAuth();
    $accepted = $this->withToken($token)->postJson("/api/v1/portal/quotes/{$quoteId}/accept")->assertOk()->json();
    expect($accepted['status'])->toBe('accepted')->and($accepted)->not->toHaveKey('total_buy_amount');
});
