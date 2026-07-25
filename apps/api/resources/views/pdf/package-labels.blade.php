<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { font-family: DejaVu Sans, sans-serif; margin: 0; color: #16222E; }
    .label { width: 100%; box-sizing: border-box; padding: 10pt 12pt; text-align: center; }
    .label + .label { page-break-before: always; }
    .head { width: 100%; font-size: 8pt; }
    .brand { font-weight: bold; letter-spacing: 2pt; }
    .brand span { color: #E8642C; }
    .colisage { float: right; font-size: 10pt; font-weight: bold; border: 1pt solid #16222E; padding: 1pt 5pt; }
    .ref { font-size: 14pt; font-weight: bold; margin: 6pt 0 2pt; }
    .qr img { width: 96pt; height: 96pt; margin: 2pt 0; }
    .dest { background: #16222E; color: #fff; padding: 5pt 4pt; margin: 4pt -12pt; }
    .dest .name { font-size: 16pt; font-weight: bold; text-transform: uppercase; letter-spacing: 1pt; }
    .dest .codes { font-size: 8.5pt; color: #cfd8e0; }
    table.info { width: 100%; border-collapse: collapse; margin-top: 5pt; font-size: 9pt; }
    table.info td { border: 0.6pt solid #999; padding: 3pt 5pt; text-align: left; }
    table.info .k { font-size: 6.5pt; text-transform: uppercase; color: #666; display: block; }
    table.info .v { font-weight: bold; font-size: 10pt; }
    .desc { font-size: 8pt; color: #444; margin-top: 4pt; text-align: left; }
    .hint { font-size: 6.5pt; color: #888; margin-top: 5pt; }
</style>
</head>
<body>
@foreach ($labels as $label)
    <div class="label">
        <div class="head">
            <span class="colisage">COLIS {{ $label['position'] }}/{{ $label['total'] }}</span>
            <span class="brand">SILA<span>RIS</span></span>
            <span> · {{ $mode === 'air' ? 'AÉRIEN' : 'MARITIME LCL' }} · {{ $shipmentReference }}</span>
        </div>

        <div class="ref">{{ $label['reference'] }}</div>
        <div class="qr"><img src="data:image/svg+xml;base64,{{ $label['qr_svg'] }}" alt="QR"></div>

        <div class="dest">
            <div class="name">{{ $label['destination_name'] }}</div>
            <div class="codes">{{ $label['origin_locode'] }} → {{ $label['destination_locode'] }}</div>
        </div>

        <table class="info">
            <tr>
                <td width="50%"><span class="k">Client</span><span class="v">{{ $label['client'] }}</span>
                    ({{ $label['client_code'] }})@if($label['client_phone'])<br>{{ $label['client_phone'] }}@endif</td>
                <td width="25%"><span class="k">Poids</span><span class="v">{{ $label['weight_kg'] !== null ? rtrim(rtrim(number_format((float) $label['weight_kg'], 1, ',', ' '), '0'), ',').' kg' : '—' }}</span></td>
                <td width="25%"><span class="k">Volume</span><span class="v">{{ $label['volume_m3'] !== null ? rtrim(rtrim(number_format((float) $label['volume_m3'], 3, ',', ' '), '0'), ',').' m³' : '—' }}</span></td>
            </tr>
        </table>
        @if ($label['description'])<div class="desc">{{ $label['description'] }}</div>@endif
        <div class="hint">Scannez le QR pour suivre ce colis · silaris</div>
    </div>
@endforeach
</body>
</html>
