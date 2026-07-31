<?php

declare(strict_types=1);

namespace Silaris\Modules\Reporting\Interface\Http\Controller;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Silaris\Modules\Reporting\Infrastructure\Export\BusinessReportExport;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rapports de gestion — marge et chiffre d'affaires.
 *
 * La marge se lit sur l'offre gagnée : une cotation acceptée porte son prix de
 * vente et son coût estimé, donc sa marge. Le chiffre d'affaires se lit sur la
 * facture émise, net des avoirs. Deux sources, deux moments du dossier — on ne
 * les confond pas.
 *
 * Les montants sont agrégés dans leur devise de saisie sans conversion : la
 * maison facture en XOF, un rapport multidevise viendrait avec ses taux.
 */
class ReportController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function business(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json($this->payload($from, $to));
    }

    /**
     * GET /v1/reports/business/export — le même rapport en classeur Excel ou en
     * PDF, à joindre à un conseil ou à retravailler hors ligne.
     */
    public function export(Request $request): Response|BinaryFileResponse
    {
        [$from, $to] = $this->range($request);
        $format = $request->validate(['format' => ['sometimes', 'in:xlsx,pdf']])['format'] ?? 'xlsx';
        $payload = $this->payload($from, $to);
        $name = 'rapport-gestion-'.$from->toDateString().'_'.$to->toDateString();

        if ($format === 'pdf') {
            return Pdf::loadView('pdf.report-business', $payload)->download($name.'.pdf');
        }

        return Excel::download(new BusinessReportExport($payload), $name.'.xlsx');
    }

    /**
     * Bornes de la période : les dates fournies, sinon les six derniers mois.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);

        return [
            isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->subMonths(5)->startOfMonth(),
            isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now()->endOfDay(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Carbon $from, Carbon $to): array
    {
        $tenantId = $this->tenant->id();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'margin' => $this->margin($tenantId, $from, $to),
            'revenue' => $this->revenue($tenantId, $from, $to),
        ];
    }

    /**
     * Marge des offres gagnées (cotations acceptées sur la période, datées de
     * leur acceptation). Le coût est l'estimation portée à la cotation — la
     * marge est donc prévisionnelle, faute de coût réalisé au dossier.
     *
     * @return array{totals: array{revenue: float, cost: float, margin: float, rate: float, won_count: int}, by_month: list<array<string, mixed>>, by_mode: list<array<string, mixed>>}
     */
    private function margin(string $tenantId, Carbon $from, Carbon $to): array
    {
        $base = fn () => DB::table('quotes')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('accepted_at')
            ->whereBetween('accepted_at', [$from, $to]);

        $revenueExpr = 'coalesce(sum(total_amount), 0) AS revenue';
        $costExpr = 'coalesce(sum(total_buy_amount), 0) AS cost';

        $totalsRow = $base()->selectRaw("{$revenueExpr}, {$costExpr}, count(*) AS won_count")->first();
        $revenue = (float) ($totalsRow->revenue ?? 0);
        $cost = (float) ($totalsRow->cost ?? 0);

        $byMonth = $base()
            ->selectRaw("to_char(date_trunc('month', accepted_at), 'YYYY-MM') AS month, {$revenueExpr}, {$costExpr}")
            ->groupByRaw('1')->orderBy('month')->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'revenue' => (float) $r->revenue,
                'cost' => (float) $r->cost,
                'margin' => round((float) $r->revenue - (float) $r->cost, 2),
            ])->all();

        $byMode = $base()
            ->selectRaw("mode, {$revenueExpr}, {$costExpr}, count(*) AS won_count")
            ->groupByRaw('mode')->orderByDesc('revenue')->get()
            ->map(fn ($r) => [
                'mode' => $r->mode,
                'revenue' => (float) $r->revenue,
                'cost' => (float) $r->cost,
                'margin' => round((float) $r->revenue - (float) $r->cost, 2),
                'rate' => self::rate((float) $r->revenue, (float) $r->cost),
                'won_count' => (int) $r->won_count,
            ])->all();

        return [
            'totals' => [
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'margin' => round($revenue - $cost, 2),
                'rate' => self::rate($revenue, $cost),
                'won_count' => (int) ($totalsRow->won_count ?? 0),
            ],
            'by_month' => $byMonth,
            'by_mode' => $byMode,
        ];
    }

    /**
     * Chiffre d'affaires facturé sur la période (date d'émission), net des
     * avoirs. Les factures synchronisées comptent comme les validées : la
     * synchronisation comptable ne les annule pas.
     *
     * @return array{total: float, by_month: list<array<string, mixed>>, by_company: list<array<string, mixed>>}
     */
    private function revenue(string $tenantId, Carbon $from, Carbon $to): array
    {
        // Un avoir se soustrait ; d'où le montant signé selon le type.
        $signed = "sum(case when type = 'credit_note' then -total_excl_tax else total_excl_tax end)";

        // Colonnes qualifiées : le regroupement par société joint `companies`,
        // qui porte aussi un `tenant_id`.
        $base = fn () => DB::table('invoices')
            ->where('invoices.tenant_id', $tenantId)
            ->whereIn('invoices.type', ['invoice', 'credit_note'])
            ->whereIn('invoices.status', ['validated', 'synced'])
            ->whereNotNull('invoices.issue_date')
            ->whereBetween('invoices.issue_date', [$from->toDateString(), $to->toDateString()]);

        $total = (float) ($base()->selectRaw("coalesce({$signed}, 0) AS net")->value('net') ?? 0);

        $byMonth = $base()
            ->selectRaw("to_char(date_trunc('month', issue_date), 'YYYY-MM') AS month, coalesce({$signed}, 0) AS invoiced")
            ->groupByRaw('1')->orderBy('month')->get()
            ->map(fn ($r) => ['month' => $r->month, 'invoiced' => (float) $r->invoiced])->all();

        $byCompany = $base()
            ->join('companies', 'companies.id', '=', 'invoices.company_id')
            ->selectRaw("companies.legal_name AS company, coalesce({$signed}, 0) AS invoiced")
            ->groupByRaw('companies.legal_name')->orderByDesc('invoiced')->get()
            ->map(fn ($r) => ['company' => $r->company, 'invoiced' => (float) $r->invoiced])->all();

        return [
            'total' => round($total, 2),
            'by_month' => $byMonth,
            'by_company' => $byCompany,
        ];
    }

    private static function rate(float $revenue, float $cost): float
    {
        return $revenue <= 0.0 ? 0.0 : round(($revenue - $cost) / $revenue * 100, 1);
    }
}
