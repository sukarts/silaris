@php
    /** @var object $mission */
    $address = is_array($company->address ?? null) ? $company->address : (json_decode((string) ($company->address ?? '{}'), true) ?: []);
    $fmt = fn ($n) => $n === null ? '—' : number_format((float) $n, 0, ',', ' ');
    $at = fn ($d) => $d === null ? null : \Illuminate\Support\Carbon::parse($d);
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
    .muted { color: #6b7280; }
    .h1 { font-size: 15px; font-weight: bold; margin: 2px 0 4px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1.5px 0; vertical-align: top; }
    .lines th { background: #f0f1f4; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1.5px solid #1f2430; }
    .lines td { padding: 6px 8px; border-bottom: 0.5px solid #d9dce2; }
    .num { text-align: right; }
    .block { margin: 18px 0 14px; padding: 10px 12px; background: #f7f8fa; border-radius: 4px; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
    .sign { border: 0.5px solid #d9dce2; border-radius: 4px; padding: 8px 10px; height: 118px; }
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
                <div class="h1">BON DE LIVRAISON</div>
                <table class="meta" style="margin-left: auto; width: auto; text-align: right;">
                    <tr><td class="muted" style="padding-right: 10px;">Mission</td><td><strong>{{ $mission->reference }}</strong></td></tr>
                    @if ($mission->shipment)
                        <tr><td class="muted" style="padding-right: 10px;">Dossier</td><td>{{ $mission->shipment->reference }}</td></tr>
                    @endif
                    @if ($at($pod->delivered_at))
                        <tr><td class="muted" style="padding-right: 10px;">Livré le</td><td>{{ $at($pod->delivered_at)->format('d/m/Y à H:i') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div class="block">
        <table class="row">
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <span class="label">Livré à</span><br>
                    <strong>{{ $client->name ?? $pod->recipient_name }}</strong>
                    @if ($client)<span class="muted"> ({{ $client->code }})</span>@endif
                </td>
                <td style="width: 50%; vertical-align: top;">
                    <span class="label">Lieu de livraison</span><br>
                    @php $last = count($stops) > 0 ? $stops[count($stops) - 1] : null; @endphp
                    <strong>{{ $last->label ?? '—' }}</strong>
                    @if ($pod->latitude !== null)
                        <br><span class="muted">{{ number_format((float) $pod->latitude, 5, ',', ' ') }}, {{ number_format((float) $pod->longitude, 5, ',', ' ') }}</span>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if (count($lines) > 0)
        <table class="lines">
            <thead>
                <tr>
                    <th style="width: 22%;">Référence</th>
                    <th>Désignation</th>
                    <th style="width: 20%;">Conteneur</th>
                    <th style="width: 14%;" class="num">Poids (kg)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td><strong>{{ $line->reference }}</strong></td>
                        <td>{{ $line->description ?? '—' }}</td>
                        <td>{{ $line->container_number ?? '—' }}</td>
                        <td class="num">{{ $fmt($line->weight) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="muted" style="padding: 8px 0;">Aucune marchandise détaillée — se reporter au dossier.</p>
    @endif

    @if (!empty($pod->remarks))
        <div style="margin-top: 14px;">
            <span class="label">Remarques</span><br>
            {{ $pod->remarks }}
        </div>
    @endif

    <table class="row" style="margin-top: 22px;">
        <tr>
            <td style="width: 48%; vertical-align: top; padding-right: 12px;">
                <div class="sign">
                    <span class="label">Reçu par</span><br>
                    <strong>{{ $pod->recipient_name }}</strong>
                    @if (!empty($pod->signature_data))
                        <div style="margin-top: 4px;">
                            <img src="{{ $pod->signature_data }}" alt="" style="max-height:74px;max-width:100%;">
                        </div>
                    @else
                        <div class="muted" style="margin-top: 26px;">Signature non recueillie</div>
                    @endif
                </div>
            </td>
            <td style="width: 52%; vertical-align: top;">
                <div class="sign">
                    <span class="label">Pour {{ $company->legal_name }}</span>
                    <div class="muted" style="margin-top: 60px; border-top: 0.5px solid #d9dce2; padding-top: 4px;">Nom, date et signature</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $company->legal_name }} — bon de livraison {{ $mission->reference }}. La marchandise voyage aux conditions générales du transitaire ; toute réserve doit être portée ci-dessus au moment de la remise.
    </div>
</body>
</html>
