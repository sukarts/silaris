@php
    $address = is_array($company->address ?? null) ? $company->address : (json_decode((string) ($company->address ?? '{}'), true) ?: []);
    $modeLabels = ['sea_fcl' => 'Maritime FCL', 'sea_lcl' => 'Maritime LCL', 'air' => 'Aérien', 'road' => 'Routier', 'multimodal' => 'Multimodal'];
    $fmt = fn ($n) => number_format((float) $n, 0, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2430; padding: 28px 34px; }
    .brand { font-size: 16px; font-weight: bold; letter-spacing: 2px; }
    .brand span { color: #e8663d; }
    .muted { color: #6b7280; }
    .h1 { font-size: 15px; font-weight: bold; margin: 2px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1.5px 0; vertical-align: top; }
    .lines th { background: #f0f1f4; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1.5px solid #1f2430; }
    .lines td { padding: 6px 8px; border-bottom: 0.5px solid #d9dce2; }
    .num { text-align: right; }
    .badge { display: inline-block; padding: 2px 8px; background: #f0f1f4; border-radius: 8px; font-size: 8px; margin-right: 4px; }
    .footer { position: fixed; bottom: 18px; left: 34px; right: 34px; font-size: 8px; color: #6b7280; border-top: 0.5px solid #d9dce2; padding-top: 6px; text-align: center; }
</style>
</head>
<body>
    <table>
        <tr>
            <td style="width: 55%;">
                <div class="brand">SILA<span>RIS</span></div>
                <div style="margin-top: 8px; font-weight: bold;">{{ $company->legal_name }}</div>
                <div class="muted">
                    {{ $address['line1'] ?? '' }}<br>
                    {{ trim(($address['city'] ?? '').' '.($address['country'] ?? '')) }}
                </div>
            </td>
            <td style="width: 45%; text-align: right;">
                <div class="h1">COTATION</div>
                <table class="meta" style="margin-left: auto; width: auto; text-align: right;">
                    <tr><td class="muted" style="padding-right: 10px;">Numéro</td><td><strong>{{ $quote->number }}</strong>@if ($quote->revision > 1) rév. {{ $quote->revision }}@endif</td></tr>
                    <tr><td class="muted" style="padding-right: 10px;">Valable jusqu'au</td><td>{{ \Illuminate\Support\Carbon::parse($quote->valid_until)->format('d/m/Y') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="margin: 22px 0 12px; padding: 10px 12px; background: #f7f8fa; border-radius: 4px;">
        <span class="muted" style="font-size: 8px; text-transform: uppercase; letter-spacing: .5px;">Client</span><br>
        <strong>{{ $quote->party->name }}</strong> <span class="muted">({{ $quote->party->code }})</span>
    </div>

    <p style="margin-bottom: 14px;">
        <span class="badge">{{ $modeLabels[$quote->mode] ?? $quote->mode }}</span>
        <span class="badge">{{ strtoupper($quote->direction) }}</span>
        <span class="badge">{{ $quote->origin_locode }} → {{ $quote->destination_locode }}</span>
        <span class="badge">Incoterm {{ $quote->incoterm_code }}</span>
    </p>

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 46%;">Prestation</th>
                <th class="num" style="width: 12%;">Quantité</th>
                <th style="width: 10%;">Unité</th>
                <th class="num" style="width: 16%;">P.U.</th>
                <th class="num" style="width: 16%;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->lines->sortBy('position') as $line)
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
                <table>
                    <tr>
                        <td style="padding: 6px 8px; font-size: 12px; font-weight: bold; border-top: 1.5px solid #1f2430;">Total</td>
                        <td class="num" style="padding: 6px 8px; font-size: 12px; font-weight: bold; border-top: 1.5px solid #1f2430;">{{ $fmt($quote->total_amount) }} {{ $quote->currency_code }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <p style="margin-top: 18px;" class="muted">
        Offre valable jusqu'au {{ \Illuminate\Support\Carbon::parse($quote->valid_until)->format('d/m/Y') }}, sous réserve de disponibilité
        d'espace et d'équipement. Taxes, surcharges et frais de destination selon conditions en vigueur au moment de l'expédition.
    </p>

    <div class="footer">{{ $company->legal_name }}</div>
</body>
</html>
