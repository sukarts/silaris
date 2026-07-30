@php
    /** @var object $awb */
    $addr = is_array($company->address ?? null) ? $company->address : (json_decode((string) ($company->address ?? '{}'), true) ?: []);
    $fmt = fn ($n) => $n === null ? '—' : number_format((float) $n, 0, ',', ' ');
    $fmt3 = fn ($n) => $n === null ? '—' : rtrim(rtrim(number_format((float) $n, 3, ',', ' '), '0'), ',');
    $at = fn ($d) => $d === null ? null : \Illuminate\Support\Carbon::parse($d);
    // Un bloc adresse (expéditeur/destinataire) sérialisé en JSONB : on affiche
    // les clés usuelles si présentes, sinon la valeur brute.
    $party = function (array $p): string {
        $lines = array_filter([
            $p['name'] ?? null,
            $p['address'] ?? $p['line1'] ?? null,
            trim(($p['postal_code'] ?? '').' '.($p['city'] ?? '')),
            $p['country'] ?? null,
            isset($p['contact']) ? 'Contact : '.$p['contact'] : null,
        ], fn ($l) => $l !== null && trim((string) $l) !== '');

        return implode('<br>', array_map('e', $lines));
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
    .muted { color: #6b7280; }
    .h1 { font-size: 15px; font-weight: bold; margin: 2px 0 4px; }
    .awbno { font-size: 18px; font-weight: bold; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1.5px 0; vertical-align: top; }
    .legs th { background: #f0f1f4; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1.5px solid #1f2430; }
    .legs td { padding: 6px 8px; border-bottom: 0.5px solid #d9dce2; }
    .parties td { width: 50%; vertical-align: top; padding: 10px 12px; background: #f7f8fa; border-radius: 4px; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
    .weights { margin: 16px 0 6px; }
    .weights td { border: 0.5px solid #d9dce2; padding: 8px 10px; width: 25%; vertical-align: top; }
    .weights .big { font-size: 14px; font-weight: bold; }
    .charge { background: #eef4ff; border-color: #b9ccf0 !important; }
    .draft { margin: 10px 0; padding: 6px 10px; background: #fff4e5; border: 0.5px solid #f0c48a; border-radius: 4px; color: #8a5a12; font-weight: bold; }
    .footer { position: fixed; bottom: 18px; left: 34px; right: 34px; font-size: 8px; color: #6b7280; border-top: 0.5px solid #d9dce2; padding-top: 6px; text-align: center; }
</style>
</head>
<body>
    <table class="row">
        <tr>
            <td style="width: 52%;">
                @if (!empty($logo))
                    <img src="{{ $logo }}" alt="" style="max-height:46px;max-width:190px;margin-bottom:6px;">
                @else
                    <div class="brand">{{ $company->legal_name }}</div>
                @endif
                <div style="margin-top: 8px; font-weight: bold;">{{ $company->legal_name }}</div>
                <div class="muted">
                    {{ $addr['line1'] ?? '' }}<br>
                    {{ trim(($addr['city'] ?? '').' '.($addr['country'] ?? '')) }}<br>
                    @if (!empty($company->tax_id)) {{ $company->tax_id }} @endif
                </div>
            </td>
            <td style="width: 48%; text-align: right;">
                <div class="h1">LETTRE DE TRANSPORT AÉRIEN</div>
                <div class="awbno">{{ $number }}</div>
                <table class="meta" style="margin-left: auto; width: auto; text-align: right; margin-top: 4px;">
                    <tr><td class="muted" style="padding-right: 10px;">Type</td><td>{{ $awb->type === 'master' ? 'Master (MAWB)' : 'House (HAWB)' }}</td></tr>
                    @if ($airline)
                        <tr><td class="muted" style="padding-right: 10px;">Compagnie</td><td>{{ $airline->name }}</td></tr>
                    @endif
                    @if ($awb->shipment)
                        <tr><td class="muted" style="padding-right: 10px;">Dossier</td><td>{{ $awb->shipment->reference }}</td></tr>
                    @endif
                    @if ($at($awb->issued_at))
                        <tr><td class="muted" style="padding-right: 10px;">Émise le</td><td>{{ $at($awb->issued_at)->format('d/m/Y') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    @if ($awb->status === 'draft')
        <div class="draft">PROFORMA — LTA non émise. Document de travail, sans valeur de contrat de transport.</div>
    @endif

    <table class="row parties" style="margin-top: 14px;">
        <tr>
            <td style="padding-right: 6px;">
                <span class="label">Expéditeur (Shipper)</span><br>
                @php $sh = is_array($awb->shipper) ? $awb->shipper : []; @endphp
                {!! $party($sh) ?: '<span class="muted">—</span>' !!}
            </td>
            <td style="padding-left: 6px;">
                <span class="label">Destinataire (Consignee)</span><br>
                @php $co = is_array($awb->consignee) ? $awb->consignee : []; @endphp
                @if ($party($co))
                    {!! $party($co) !!}
                @elseif ($client)
                    <strong>{{ $client->name }}</strong> <span class="muted">({{ $client->code }})</span>
                @else
                    <span class="muted">—</span>
                @endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 16px;">
        <span class="label">Acheminement</span>
        @if (count($awb->legs) > 0)
            <table class="legs" style="margin-top: 4px;">
                <thead>
                    <tr>
                        <th style="width: 8%;">Seg.</th>
                        <th>De</th>
                        <th>Vers</th>
                        <th style="width: 14%;">Vol</th>
                        <th style="width: 18%;">Départ</th>
                        <th style="width: 18%;">Arrivée</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($awb->legs as $leg)
                        <tr>
                            <td>{{ $leg->position }}</td>
                            <td><strong>{{ $leg->origin_iata }}</strong> <span class="muted">{{ $airports[$leg->origin_iata] ?? '' }}</span></td>
                            <td><strong>{{ $leg->destination_iata }}</strong> <span class="muted">{{ $airports[$leg->destination_iata] ?? '' }}</span></td>
                            <td class="mono">{{ $leg->flight_number }}</td>
                            <td>{{ $at($leg->departure_at)?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td>{{ $at($leg->arrival_at)?->format('d/m/Y H:i') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="muted" style="padding: 6px 0;">Aucun segment de vol renseigné.</p>
        @endif
    </div>

    <table class="row weights">
        <tr>
            <td>
                <span class="label">Colis</span><br>
                <span class="big">{{ $awb->packages_count ?? '—' }}</span>
            </td>
            <td>
                <span class="label">Poids brut</span><br>
                <span class="big">{{ $fmt3($awb->gross_weight_kg) }}</span> kg
            </td>
            <td>
                <span class="label">Volume</span><br>
                <span class="big">{{ $fmt3($awb->volume_m3) }}</span> m³
            </td>
            <td class="charge">
                <span class="label">Poids taxable (IATA)</span><br>
                <span class="big">{{ $fmt3($awb->chargeable_weight_kg) }}</span> kg
            </td>
        </tr>
    </table>
    <p class="muted" style="font-size: 8px;">Poids taxable = max(poids brut ; volume × 166,667 kg/m³), règle IATA.</p>

    @if (!empty($awb->goods_description))
        <div style="margin-top: 14px;">
            <span class="label">Nature et quantité de la marchandise</span><br>
            {{ $awb->goods_description }}
        </div>
    @endif

    <div class="footer">
        {{ $company->legal_name }} — LTA {{ $number }}. La marchandise voyage aux conditions du contrat de transport aérien et des conditions générales du transitaire.
    </div>
</body>
</html>
