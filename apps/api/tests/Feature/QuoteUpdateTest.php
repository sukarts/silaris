<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Brouillon avec deux lignes, prêt à être repris. */
function seedDraftQuoteWithLines(array $ids): string
{
    $quoteId = seedAcceptedQuote($ids, overrides: ['status' => 'draft', 'accepted_at' => null, 'total_amount' => 500_000]);
    DB::table('quotes')->where('id', $quoteId)->update(['approved_at' => null, 'approved_by' => null]);
    DB::table('quote_lines')->insert([
        'id' => (string) Str::uuid7(), 'quote_id' => $quoteId, 'position' => 1,
        'service_code' => 'FREIGHT', 'description' => 'Fret maritime', 'quantity' => 1, 'unit' => 'container',
        'unit_price' => 500_000, 'currency_code' => 'XOF', 'category' => 'other',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $quoteId;
}

it('reprend un brouillon, remplace ses lignes et recalcule le total', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuoteWithLines($ids);

    $this->withToken(tokenFor($ids['user_sales_manager']))
        ->patchJson("/api/v1/quotes/{$quoteId}", [
            'lines' => [
                ['service_code' => 'DD', 'description' => 'Droit de douane', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 300_000, 'currency_code' => 'XOF', 'category' => 'customs'],
                ['service_code' => 'LIVRAISON', 'description' => 'Livraison', 'quantity' => 2, 'unit' => 'flat', 'unit_price' => 100_000, 'currency_code' => 'XOF', 'category' => 'other'],
            ],
        ])
        ->assertOk();

    expect((float) DB::table('quotes')->where('id', $quoteId)->value('total_amount'))->toBe(500_000.0)
        ->and(DB::table('quote_lines')->where('quote_id', $quoteId)->count())->toBe(2);
});

it('annule la validation quand le brouillon est repris', function (): void {
    // Sans cela, on relèverait le prix après l'aval du responsable et le client
    // recevrait un montant que personne n'a validé.
    $ids = seedCore();
    $quoteId = seedDraftQuoteWithLines($ids);
    $token = tokenFor($ids['user_sales_manager']);

    $this->withToken($token)->postJson("/api/v1/quotes/{$quoteId}/approve")->assertOk();
    expect(DB::table('quotes')->where('id', $quoteId)->value('approved_at'))->not->toBeNull();

    freshAuth();
    $this->withToken($token)->patchJson("/api/v1/quotes/{$quoteId}", [
        'lines' => [['service_code' => 'FREIGHT', 'description' => 'Fret', 'quantity' => 1, 'unit' => 'container', 'unit_price' => 900_000, 'currency_code' => 'XOF', 'category' => 'other']],
    ])->assertOk();

    expect(DB::table('quotes')->where('id', $quoteId)->value('approved_at'))->toBeNull();

    // La transmission redevient impossible tant qu'elle n'est pas re-validée.
    freshAuth();
    $this->withToken($token)->postJson("/api/v1/quotes/{$quoteId}/send")
        ->assertStatus(422)->assertJsonPath('error_code', 'quote.not_approved');
});

it('refuse de reprendre une cotation déjà transmise', function (): void {
    $ids = seedCore();
    $quoteId = seedAcceptedQuote($ids, overrides: ['status' => 'sent', 'accepted_at' => null, 'sent_at' => now()]);

    $this->withToken(tokenFor($ids['user_sales_manager']))
        ->patchJson("/api/v1/quotes/{$quoteId}", [
            'lines' => [['service_code' => 'X', 'description' => 'X', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 1, 'currency_code' => 'XOF']],
        ])
        ->assertNotFound();
});

it('refuse la reprise à un rôle sans droit de mise à jour', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuoteWithLines($ids);

    // Le directeur valide mais ne modifie pas le prix : quotes.approve sans quotes.update.
    $this->withToken(tokenFor($ids['user_director']))
        ->patchJson("/api/v1/quotes/{$quoteId}", [
            'lines' => [['service_code' => 'X', 'description' => 'X', 'quantity' => 1, 'unit' => 'flat', 'unit_price' => 1, 'currency_code' => 'XOF']],
        ])
        ->assertForbidden();
});
