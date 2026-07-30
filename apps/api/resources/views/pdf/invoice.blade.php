@php
    /** @var object $invoice */
    $typeLabels = ['invoice' => 'FACTURE', 'proforma' => 'FACTURE PROFORMA', 'credit_note' => 'AVOIR'];
    $address = is_array($company->address ?? null) ? $company->address : (json_decode((string) ($company->address ?? '{}'), true) ?: []);
    $settings = is_array($company->invoice_settings ?? null) ? $company->invoice_settings : (json_decode((string) ($company->invoice_settings ?? '{}'), true) ?: []);
    $fne = is_array($company->fne_settings ?? null) ? $company->fne_settings : (json_decode((string) ($company->fne_settings ?? '{}'), true) ?: []);
    $fmt = fn ($n) => number_format((float) $n, 0, ',', ' ');

    // La facture normalisée n'a de sens qu'une fois certifiée : elle porte alors
    // le numéro fiscal de la DGI. Avant, c'est un document interne ordinaire.
    $certified = ($invoice->fne_reference ?? null) !== null;

    $contact = $invoice->party->contacts->firstWhere('is_primary', true)
        ?? $invoice->party->contacts->first();
    $clientAddress = is_array($invoice->party->address ?? null)
        ? $invoice->party->address
        : (json_decode((string) ($invoice->party->address ?? '{}'), true) ?: []);

    $paymentLabels = ['deferred' => 'Différé', 'cash' => 'Espèces', 'transfer' => 'Virement', 'card' => 'Carte', 'mobile-money' => 'Mobile money'];

    // Code de taxe affiché par ligne — même règle que ce qui part à la DGI :
    // TVA à 18, TVAB à 9, TVAD (exonération légale) pour le reste.
    $taxLabel = function ($line) use ($taxRates) {
        $rate = $line->tax_rate_id !== null ? (float) ($taxRates[$line->tax_rate_id] ?? 0) : 0.0;
        $code = abs($rate - 18.0) < 0.001 ? 'TVA' : (abs($rate - 9.0) < 0.001 ? 'TVAB' : 'TVAD');

        return $code.' ('.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').')';
    };
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2430; padding: 28px 34px; }
    .row { width: 100%; }
    .brand { font-size: 16px; font-weight: bold; letter-spacing: 2px; }
    .brand span { color: #e8663d; }
    .muted { color: #6b7280; }
    .h1 { font-size: 15px; font-weight: bold; margin: 2px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1.5px 0; vertical-align: top; }
    .lines th { background: #f0f1f4; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1.5px solid #1f2430; }
    .lines td { padding: 6px 8px; border-bottom: 0.5px solid #d9dce2; }
    .num { text-align: right; }
    .totals td { padding: 4px 8px; }
    .totals .grand { font-size: 12px; font-weight: bold; border-top: 1.5px solid #1f2430; }
    .footer { position: fixed; bottom: 18px; left: 34px; right: 34px; font-size: 8px; color: #6b7280; border-top: 0.5px solid #d9dce2; padding-top: 6px; text-align: center; }
    /* Mise en page de la facture normalisée */
    .issuer { border: 1px solid #1f2430; border-radius: 6px; padding: 8px 10px; font-size: 9px; line-height: 1.5; }
    .issuer .name { font-weight: bold; font-size: 11px; }
    .fne-visual { display: inline-block; border: 1.5px solid #e8663d; border-radius: 50%; width: 74px; height: 74px; text-align: center; color: #e8663d; font-size: 6.5px; font-weight: bold; line-height: 1.2; padding-top: 16px; letter-spacing: .3px; }
    .detail td { padding: 1px 0; vertical-align: top; font-size: 9px; }
    .detail .k { color: #6b7280; padding-right: 6px; white-space: nowrap; }
    .fnenum { font-size: 11px; font-weight: bold; }
    .fne-lines th { background: #fff; border-bottom: 1px solid #1f2430; text-align: left; padding: 5px 6px; font-size: 8.5px; }
    .fne-lines td { padding: 5px 6px; border-bottom: 0.5px solid #d9dce2; font-size: 9px; vertical-align: top; }
    .fne-tot td { padding: 4px 8px; border: 0.5px solid #d9dce2; font-size: 10px; }
</style>
</head>
<body>
@if ($certified)
    {{-- ─────────── Facture Normalisée Électronique (certifiée DGI) ─────────── --}}
    <table class="row">
        <tr>
            <td style="width: 52%; vertical-align: top;">
                <div class="issuer">
                    <div class="name">{{ $company->legal_name }}</div>
                    @if (!empty($fne['ncc']))NCC : {{ $fne['ncc'] }}<br>@endif
                    @if (!empty($fne['regime']))Régime d'imposition : {{ $fne['regime'] }}<br>@endif
                    @if (!empty($fne['tax_center']))Centre des impôts : {{ $fne['tax_center'] }}@endif
                </div>
            </td>
            <td style="width: 48%; vertical-align: top; text-align: right;">
                @if ($invoice->type === 'credit_note')
                    <div class="fnenum">Facture d'avoir N° {{ $invoice->fne_reference }}</div>
                    @if (!empty($originalFne))<div class="muted" style="font-size: 10px;">Facture initiale N° {{ $originalFne }}</div>@endif
                @else
                    <div class="fnenum">Facture de vente N° {{ $invoice->fne_reference }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="row" style="margin-top: 14px;">
        <td style="width: 55%; vertical-align: top;">
            <table class="detail">
                <tr><td class="k">RCCM</td><td>{{ $company->tax_id ?? '—' }}</td></tr>
                <tr><td class="k">Établissement</td><td>{{ $fne['establishment'] ?? $company->legal_name }}</td></tr>
                <tr><td class="k">Adresse</td><td>{{ trim(($address['line1'] ?? '').' '.($address['city'] ?? '')) ?: '—' }}</td></tr>
                <tr><td class="k">Nom du vendeur</td><td>{{ $invoice->fne_seller_name ?? '—' }}</td></tr>
                <tr><td class="k">Nom de PDV</td><td>{{ $fne['point_of_sale'] ?? '—' }}</td></tr>
                <tr><td class="k">Date et heure</td><td>{{ \Illuminate\Support\Carbon::parse($invoice->fne_certified_at)->format('d/m/Y H:i:s') }}</td></tr>
                <tr><td class="k">Mode de paiement</td><td>{{ $paymentLabels['deferred'] }}</td></tr>
            </table>
        </td>
        <td style="width: 20%; vertical-align: top; text-align: center;">
            @if (!empty($fneQr))<img src="{{ $fneQr }}" alt="QR FNE" style="width: 78px; height: 78px;">@endif
        </td>
        <td style="width: 25%; vertical-align: top; text-align: center;">
            <div class="fne-visual">FACTURE<br>NORMALISÉE<br>ÉLECTRONIQUE</div>
            <div style="margin-top: 10px; text-align: left;">
                <strong>Client</strong>
                <table class="detail" style="margin-top: 2px;">
                    <tr><td class="k">Nom</td><td>{{ $invoice->party->name }}</td></tr>
                    <tr><td class="k">Adresse</td><td>{{ $clientAddress['line1'] ?? ($contact->email ?? '—') }}</td></tr>
                    @if (!empty($invoice->party->ncc))<tr><td class="k">NCC</td><td>{{ $invoice->party->ncc }}</td></tr>@endif
                    @if (!empty($invoice->party->tax_regime))<tr><td class="k">Régime</td><td>{{ $invoice->party->tax_regime }}</td></tr>@endif
                </table>
            </div>
        </td>
    </table>

    <table class="fne-lines" style="margin-top: 16px;">
        <thead>
            <tr>
                <th style="width: 14%;">Réf</th>
                <th style="width: 34%;">Désignation</th>
                <th class="num" style="width: 12%;">P.U HT</th>
                <th class="num" style="width: 6%;">Qté</th>
                <th style="width: 8%;">Unité</th>
                <th style="width: 12%;">Taxes</th>
                <th class="num" style="width: 14%;">Montant HT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines->sortBy('position') as $line)
            <tr>
                <td class="muted">{{ $line->service_code }}</td>
                <td>{{ $line->description }}</td>
                <td class="num">{{ $fmt($line->unit_price) }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', ' '), '0'), ',') }}</td>
                <td>{{ $line->unit }}</td>
                <td>{{ $taxLabel($line) }}</td>
                <td class="num">{{ $fmt($line->line_total) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 12px;">
        <tr>
            <td style="width: 58%;"></td>
            <td>
                <table class="fne-tot">
                    <tr><td>TOTAL HT</td><td class="num"><strong>{{ $fmt($invoice->total_excl_tax) }}</strong></td></tr>
                    <tr><td>TVA</td><td class="num">{{ $fmt($invoice->total_tax) }}</td></tr>
                    <tr><td><strong>TOTAL TTC</strong></td><td class="num"><strong>{{ $fmt($invoice->total_incl_tax) }} {{ $invoice->currency_code }}</strong></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p class="muted" style="margin-top: 14px; font-size: 8px;">
        Facture certifiée par la Direction Générale des Impôts de Côte d'Ivoire — scannez le QR code pour la vérifier.
        @if ($invoice->fne_template) Gabarit {{ $invoice->fne_template }}.@endif
    </p>
@else
    {{-- ─────────── Document interne, non encore certifié ─────────── --}}
    <table class="row">
        <tr>
            <td style="width: 55%;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" alt="" style="max-height:46px;max-width:190px;margin-bottom:6px;">
                @else
                    <div class="brand">{{ $company->legal_name }}</div>
                @endif
                <div style="margin-top: 8px; font-weight: bold;">{{ $company->legal_name }}</div>
                <div class="muted">
                    {{ $address['line1'] ?? '' }}<br>
                    {{ trim(($address['city'] ?? '').' '.($address['country'] ?? '')) }}<br>
                    @if (!empty($company->tax_id)) {{ $company->tax_id }} @endif
                </div>
            </td>
            <td style="width: 45%; text-align: right;">
                <div class="h1">{{ $typeLabels[$invoice->type] ?? 'FACTURE' }}</div>
                <table class="meta" style="margin-left: auto; width: auto; text-align: right;">
                    <tr><td class="muted" style="padding-right: 10px;">Numéro</td><td><strong>{{ $invoice->number ?? 'BROUILLON' }}</strong></td></tr>
                    @if ($invoice->issue_date)<tr><td class="muted" style="padding-right: 10px;">Émise le</td><td>{{ \Illuminate\Support\Carbon::parse($invoice->issue_date)->format('d/m/Y') }}</td></tr>@endif
                    @if ($invoice->due_date)<tr><td class="muted" style="padding-right: 10px;">Échéance</td><td>{{ \Illuminate\Support\Carbon::parse($invoice->due_date)->format('d/m/Y') }}</td></tr>@endif
                    @if ($invoice->shipment)<tr><td class="muted" style="padding-right: 10px;">Dossier</td><td>{{ $invoice->shipment->reference }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <div style="margin: 22px 0 16px; padding: 10px 12px; background: #f7f8fa; border-radius: 4px;">
        <span class="muted" style="font-size: 8px; text-transform: uppercase; letter-spacing: .5px;">Facturé à</span><br>
        <strong>{{ $invoice->party->name }}</strong> <span class="muted">({{ $invoice->party->code }})</span>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 46%;">Désignation</th>
                <th class="num" style="width: 12%;">Quantité</th>
                <th style="width: 10%;">Unité</th>
                <th class="num" style="width: 16%;">P.U.</th>
                <th class="num" style="width: 16%;">Total HT</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines->sortBy('position') as $line)
            <tr>
                <td>{{ $line->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, ',', ' '), '0'), ',') }}</td>
                <td>{{ $line->unit }}</td>
                <td class="num">{{ $fmt($line->unit_price) }}</td>
                <td class="num">{{ $fmt($line->line_total) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table style="margin-top: 14px;">
        <tr>
            <td style="width: 58%;"></td>
            <td>
                <table class="totals">
                    <tr><td class="muted">Total HT</td><td class="num">{{ $fmt($invoice->total_excl_tax) }} {{ $invoice->currency_code }}</td></tr>
                    <tr><td class="muted">TVA</td><td class="num">{{ $fmt($invoice->total_tax) }} {{ $invoice->currency_code }}</td></tr>
                    <tr class="grand"><td class="grand">Total TTC</td><td class="num grand">{{ $fmt($invoice->total_incl_tax) }} {{ $invoice->currency_code }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    @if (($invoice->party->payment_terms_days ?? null) && $invoice->type !== 'credit_note')
    <p style="margin-top: 18px;" class="muted">Conditions de règlement : {{ $invoice->party->payment_terms_days }} jours.</p>
    @endif
    @if ($invoice->type === 'proforma')
    <p style="margin-top: 6px;" class="muted">Document provisoire — ne vaut pas facture définitive.</p>
    @endif
    @if (($fne['enabled'] ?? false) && $invoice->status !== 'draft')
    <p style="margin-top: 6px;" class="muted">Facture non encore certifiée FNE — à certifier avant remise au client.</p>
    @endif
@endif

    <div class="footer">{{ $settings['footer'] ?? $company->legal_name }}</div>
</body>
</html>
