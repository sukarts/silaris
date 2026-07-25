<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it("journalise automatiquement la création d'une fiche CRM avec l'auteur et les valeurs", function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'code' => 'AUDIT1', 'name' => 'Client Audité',
        'currency_code' => 'XOF', 'payment_terms_days' => 30,
    ])->assertCreated();

    $log = DB::table('audit_logs')->where('entity_type', 'parties')->where('action', 'created')->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($ids['user_admin'])
        ->and(json_decode((string) $log->new_values, true)['name'])->toBe('Client Audité')
        ->and($log->old_values)->toBeNull();
});

it('journalise une mise à jour avec le diff old/new limité aux colonnes modifiées', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);

    $partyId = $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'code' => 'AUDIT2', 'name' => 'Avant',
    ])->json('id');
    freshAuth();

    $this->withToken($token)->patchJson("/api/v1/parties/{$partyId}", ['name' => 'Après'])->assertOk();

    $log = DB::table('audit_logs')->where('entity_type', 'parties')->where('action', 'updated')->first();
    expect($log)->not->toBeNull();
    $old = json_decode((string) $log->old_values, true);
    $new = json_decode((string) $log->new_values, true);
    expect($new['name'])->toBe('Après')
        ->and($old['name'])->toBe('Avant')
        ->and($new)->not->toHaveKey('updated_at');
});

it('masque les colonnes sensibles (password_hash) dans le journal', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_admin']))->postJson('/api/v1/auth/change-password', [
        'current_password' => 'Str0ng!Passw0rd',
        'new_password' => 'N0uveau!MotDePasse2026',
    ])->assertOk();

    $log = DB::table('audit_logs')->where('entity_type', 'users')->where('action', 'updated')
        ->orderByDesc('occurred_at')->first();
    expect($log)->not->toBeNull();
    $new = json_decode((string) $log->new_values, true);
    expect($new['password_hash'])->toBe('•••');
});

it("journalise la suppression d'une fiche", function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);

    $partyId = $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'prospect', 'kind' => 'company', 'code' => 'AUDIT3', 'name' => 'À supprimer',
    ])->json('id');
    freshAuth();

    $this->withToken($token)->deleteJson("/api/v1/parties/{$partyId}")->assertNoContent();

    $log = DB::table('audit_logs')->where('entity_type', 'parties')->where('action', 'deleted')->first();
    expect($log)->not->toBeNull()
        ->and(json_decode((string) $log->old_values, true)['name'])->toBe('À supprimer');
});

it("l'API de lecture du journal expose les entrées auto-générées", function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);

    $this->withToken($token)->postJson('/api/v1/parties', [
        'type' => 'client', 'kind' => 'company', 'code' => 'AUDIT4', 'name' => 'Visible au journal',
    ])->assertCreated();
    freshAuth();

    $this->withToken($token)->getJson('/api/v1/admin/audit-logs?entity_type=parties')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'created')
        ->assertJsonPath('data.0.entity_type', 'parties');
});
