@php
    /** @var object $invoice */
    $typeLabels = ['invoice' => 'FACTURE', 'proforma' => 'FACTURE PROFORMA', 'credit_note' => 'AVOIR'];
    $address = is_array($company->address ?? null) ? $company->address : (json_decode((string) ($company->address ?? '{}'), true) ?: []);
    $settings = is_array($company->invoice_settings ?? null) ? $company->invoice_settings : (json_decode((string) ($company->invoice_settings ?? '{}'), true) ?: []);
    $fmt = fn ($n) => number_format((float) $n, 0, ',', ' ');
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
</style>
</head>
<body>
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

    {{-- Certification FNE : QR, numéro fiscal et mention obligatoires dès que la
         DGI a certifié la facture. Un document non certifié ne les porte pas. --}}
    @if (($fneQr ?? null) && ($invoice->fne_reference ?? null))
    <table style="margin-top: 20px; border-top: 1px solid #d9dce2; padding-top: 10px;">
        <tr>
            <td style="width: 90px; vertical-align: top;">
                <img src="{{ $fneQr }}" alt="QR FNE" style="width: 84px; height: 84px;">
            </td>
            <td style="vertical-align: top; padding-left: 10px; font-size: 9px;">
                <div style="font-weight: bold; letter-spacing: 1px; color: #e8663d;">FACTURE NORMALISÉE ÉLECTRONIQUE</div>
                <div style="margin-top: 3px;">Numéro fiscal : <strong>{{ $invoice->fne_reference }}</strong></div>
                <div>Certifiée le {{ \Illuminate\Support\Carbon::parse($invoice->fne_certified_at)->format('d/m/Y à H:i') }}
                    @if ($invoice->fne_template) · {{ $invoice->fne_template }}@endif</div>
                <div class="muted" style="margin-top: 3px;">Facture certifiée par la Direction Générale des Impôts de Côte d'Ivoire. Scannez le QR code pour la vérifier.</div>
            </td>
        </tr>
    </table>
    @endif

    <div class="footer">{{ $settings['footer'] ?? $company->legal_name }}</div>
</body>
</html>
