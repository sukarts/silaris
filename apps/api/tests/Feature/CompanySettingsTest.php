<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Silaris\Modules\Shipment\Infrastructure\Persistence\SequenceReferenceGenerator;

uses(RefreshDatabase::class);

it('applique le format de référence choisi par le transitaire', function (): void {
    $ids = seedCore();
    DB::table('companies')->where('id', $ids['company'])->update([
        'shipment_settings' => json_encode(['reference_format' => '{PREFIX}/{BRANCH}/{YY}/{SEQ:4}', 'reference_prefix' => 'SAHA']),
    ]);

    $reference = app(SequenceReferenceGenerator::class)->nextShipmentReference($ids['branch']);

    expect($reference)->toMatch('#^SAHA/[A-Z0-9]+/'.date('y').'/\d{4}$#');
});

it('retombe sur le format historique sans réglage', function (): void {
    $ids = seedCore();

    expect(app(SequenceReferenceGenerator::class)->nextShipmentReference($ids['branch']))
        ->toMatch('/^[A-Z0-9]+-'.date('Y').'-\d{5}$/');
});

it('donne un aperçu du format sans consommer de séquence', function (): void {
    $ids = seedCore();

    $preview = $this->withToken(tokenFor($ids['user_admin']))
        ->getJson("/api/v1/admin/companies/{$ids['company']}/reference-preview?format=".urlencode('{PREFIX}-{YEAR}-{SEQ:3}').'&prefix=ACME')
        ->assertOk()->json('preview');

    expect($preview)->toBe('ACME-'.date('Y').'-128');
    // Aucune séquence consommée : la génération réelle repart à 1.
    expect(app(SequenceReferenceGenerator::class)->nextShipmentReference($ids['branch']))->toContain('-00001');
});

it('refuse un format sans séquence (risque de collision)', function (): void {
    $ids = seedCore();

    $this->withToken(tokenFor($ids['user_admin']))
        ->patchJson("/api/v1/admin/companies/{$ids['company']}", [
            'shipment_settings' => ['reference_format' => '{PREFIX}-{YEAR}'],
        ])->assertUnprocessable();
});

it('enregistre les informations légales et crée une agence', function (): void {
    $ids = seedCore();
    $token = tokenFor($ids['user_admin']);

    $this->withToken($token)->patchJson("/api/v1/admin/companies/{$ids['company']}", [
        'legal_name' => 'SAHA TRANSIT SA',
        'tax_id' => 'CI-ABJ-2026-B-0042',
        'address' => ['line1' => 'Zone portuaire', 'city' => 'Abidjan', 'country' => 'CI'],
    ])->assertOk()->assertJsonPath('legal_name', 'SAHA TRANSIT SA');

    $this->withToken($token)->postJson("/api/v1/admin/companies/{$ids['company']}/branches", [
        'name' => 'Agence San-Pédro', 'code' => 'SPY', 'timezone' => 'Africa/Abidjan',
    ])->assertCreated()->assertJsonPath('code', 'SPY');
});

it('appose la marque du transitaire sur les PDF, jamais celle de la solution', function (): void {
    $ids = seedCore();
    DB::table('companies')->where('id', $ids['company'])->update(['legal_name' => 'SAHA TRANSIT SA']);
    $invoiceId = seedInvoiceWithLine($ids);

    $pdf = $this->withToken(tokenFor($ids['user_admin']))
        ->get("/api/v1/invoices/{$invoiceId}/pdf")->assertOk()->getContent();

    expect($pdf)->toStartWith('%PDF')->not->toContain('SILARIS');
});

it('expose la marque du transitaire au suivi public', function (): void {
    $ids = seedCore();
    DB::table('companies')->where('id', $ids['company'])->update(['legal_name' => 'SAHA TRANSIT SA']);
    seedShipmentFor($ids, $ids['client'], 'BRD-2026-00001');

    $this->getJson('/api/v1/public/tracking?q=BRD-2026-00001')
        ->assertOk()
        ->assertJsonPath('tenant_name', 'SAHA TRANSIT SA')
        ->assertJsonStructure(['logo_url']);
});

it('sert le logo via l\'API, sans authentification ni URL signée', function (): void {
    $ids = seedCore();
    Storage::fake('local');
    config(['filesystems.documents_disk' => 'local']);
    $key = 'tenants/'.$ids['tenant'].'/branding/logo-test.png';
    Storage::disk('local')->put($key, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
    ));
    DB::table('companies')->where('id', $ids['company'])->update(['logo_document_id' => $key]);

    $this->get("/api/v1/public/companies/{$ids['company']}/logo")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('renvoie 404 quand la société n\'a pas de logo', function (): void {
    $ids = seedCore();

    $this->get("/api/v1/public/companies/{$ids['company']}/logo")->assertNotFound();
});
