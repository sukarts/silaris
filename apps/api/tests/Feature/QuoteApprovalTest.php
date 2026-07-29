<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** Cotation au brouillon, prête à être validée puis transmise. */
function seedDraftQuote(array $ids): string
{
    $quoteId = seedAcceptedQuote($ids, overrides: ['status' => 'draft', 'accepted_at' => null]);
    DB::table('quotes')->where('id', $quoteId)->update(['approved_at' => null, 'approved_by' => null]);

    return $quoteId;
}

it('refuse de transmettre une cotation non validée en interne', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuote($ids);

    $this->withToken(tokenFor($ids['user_sales_manager']))
        ->postJson("/api/v1/quotes/{$quoteId}/send")
        ->assertStatus(422)->assertJsonPath('error_code', 'quote.not_approved');

    expect(DB::table('quotes')->where('id', $quoteId)->value('status'))->toBe('draft');
});

it('laisse le responsable commercial valider puis transmettre', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuote($ids);
    $token = tokenFor($ids['user_sales_manager']);

    $this->withToken($token)->postJson("/api/v1/quotes/{$quoteId}/approve")->assertOk();
    $this->withToken($token)->postJson("/api/v1/quotes/{$quoteId}/send")->assertOk();

    $quote = DB::table('quotes')->where('id', $quoteId)->first(['status', 'approved_by', 'approved_at']);
    expect($quote->status)->toBe('sent')
        ->and($quote->approved_by)->toBe($ids['user_sales_manager'])
        ->and($quote->approved_at)->not->toBeNull();
});

it('laisse le directeur valider une cotation', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuote($ids);

    $this->withToken(tokenFor($ids['user_director']))
        ->postJson("/api/v1/quotes/{$quoteId}/approve")->assertOk();

    expect(DB::table('quotes')->where('id', $quoteId)->value('approved_by'))->toBe($ids['user_director']);
});

it('refuse la validation à qui n\'engage pas la société', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuote($ids);

    // L'agent transit prépare des dossiers, il n'engage pas de prix.
    $this->withToken(tokenFor($ids['user_transit_agent']))
        ->postJson("/api/v1/quotes/{$quoteId}/approve")->assertForbidden();
});

it('ne valide pas deux fois la même cotation', function (): void {
    $ids = seedCore();
    $quoteId = seedDraftQuote($ids);
    $token = tokenFor($ids['user_sales_manager']);

    $this->withToken($token)->postJson("/api/v1/quotes/{$quoteId}/approve")->assertOk();
    $this->withToken($token)->postJson("/api/v1/quotes/{$quoteId}/approve")
        ->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'déjà validée'));
});

it('interdit au commercial de valider sa propre cotation', function (): void {
    // La validation engage un prix : elle reste au directeur, à l'administration
    // et au responsable commercial. Le commercial la prépare, il ne la valide pas.
    $ids = seedCore();
    $quoteId = seedDraftQuote($ids);
    $sales = seedUserWithRole($ids, 'sales', 'commercial@test.local');

    $this->withToken(tokenFor($sales))
        ->postJson("/api/v1/quotes/{$quoteId}/approve")->assertForbidden();

    // Il peut néanmoins la reprendre : préparer le prix reste son travail.
    freshAuth();
    $this->withToken(tokenFor($sales))->patchJson("/api/v1/quotes/{$quoteId}", [
        'lines' => [['service_code' => 'FREIGHT', 'description' => 'Fret', 'quantity' => 1, 'unit' => 'container', 'unit_price' => 750_000, 'currency_code' => 'XOF']],
    ])->assertOk();
});
