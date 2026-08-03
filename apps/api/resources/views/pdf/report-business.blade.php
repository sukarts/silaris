@php
    /** @var array $period */
    $m = $margin['totals'];
    $fmt = fn ($n) => number_format((float) $n, 0, ',', ' ');
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1f2430; padding: 28px 34px; }
    .h1 { font-size: 16px; font-weight: bold; }
    .muted { color: #6b7280; }
    .section { font-size: 12px; font-weight: bold; margin: 18px 0 6px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f0f1f4; text-align: left; padding: 5px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1.5px solid #1f2430; }
    td { padding: 5px 8px; border-bottom: 0.5px solid #d9dce2; }
    .num { text-align: right; }
    .cards { margin-top: 8px; }
    .cards td { border: 0.5px solid #d9dce2; padding: 8px 10px; width: 25%; }
    .cards .big { font-size: 14px; font-weight: bold; }
    .label { font-size: 8px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
</style>
</head>
<body>
    <div class="h1">Rapport de gestion</div>
    <div class="muted">Période du {{ \Illuminate\Support\Carbon::parse($period['from'])->format('d/m/Y') }}
        au {{ \Illuminate\Support\Carbon::parse($period['to'])->format('d/m/Y') }}</div>

    <div class="section">Marge des offres gagnées</div>
    <table class="cards">
        <tr>
            <td><span class="label">CA vendu</span><br><span class="big">{{ $fmt($m['revenue']) }}</span></td>
            <td><span class="label">Coût estimé</span><br><span class="big">{{ $fmt($m['cost']) }}</span></td>
            <td><span class="label">Marge prév.</span><br><span class="big">{{ $fmt($m['margin']) }}</span></td>
            <td><span class="label">Taux</span><br><span class="big">{{ number_format((float) $m['rate'], 1, ',', ' ') }} %</span></td>
        </tr>
    </table>
    <p class="muted" style="margin-top:4px;font-size:8px;">Marge prévisionnelle : le coût est l'estimation portée à la cotation. {{ $m['won_count'] }} offre(s) gagnée(s).</p>

    <div class="section">Marge par mode</div>
    <table>
        <thead><tr><th>Mode</th><th class="num">CA</th><th class="num">Coût</th><th class="num">Marge</th><th class="num">Taux</th><th class="num">Offres</th></tr></thead>
        <tbody>
            @forelse ($margin['by_mode'] as $row)
                <tr>
                    <td>{{ $row['mode'] }}</td>
                    <td class="num">{{ $fmt($row['revenue']) }}</td>
                    <td class="num">{{ $fmt($row['cost']) }}</td>
                    <td class="num">{{ $fmt($row['margin']) }}</td>
                    <td class="num">{{ number_format((float) $row['rate'], 1, ',', ' ') }} %</td>
                    <td class="num">{{ $row['won_count'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">Aucune offre gagnée sur la période.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="section">Chiffre d'affaires facturé <span class="muted" style="font-weight:normal;">(net des avoirs) — total {{ $fmt($revenue['total']) }}</span></div>
    <table>
        <thead><tr><th>Société</th><th class="num">Facturé</th></tr></thead>
        <tbody>
            @forelse ($revenue['by_company'] as $row)
                <tr><td>{{ $row['company'] }}</td><td class="num">{{ $fmt($row['invoiced']) }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">Aucune facture sur la période.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
