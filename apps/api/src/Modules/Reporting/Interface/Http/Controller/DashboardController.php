<?php

declare(strict_types=1);

namespace Silaris\Modules\Reporting\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Auth\CurrentUser;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/**
 * Tableau de bord — agrégat unique pour limiter les allers-retours.
 * S'appuie sur les vues métier (v_shipments_list, v_demurrage_alerts, v_active_delays).
 */
class DashboardController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentUser $currentUser,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->tenant->id();

        $shipments = DB::table('v_shipments_list')
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at');

        // Scope agences identique à la liste des dossiers.
        if (($branchIds = $this->currentUser->branchScope()) !== null) {
            $shipments->whereIn('branch_code', DB::table('branches')->whereIn('id', $branchIds)->pluck('code'));
        }

        $open = $shipments->get();

        // ——— KPIs ———
        $kpis = [
            'active_shipments' => $open->count(),
            'import' => $open->where('direction', 'import')->count(),
            'export' => $open->where('direction', 'export')->count(),
            'containers_active' => (int) $open->sum('active_containers'),
            'delays' => $open->where('is_delayed', true)->count(),
            'missing_documents' => (int) $open->sum('missing_documents'),
            'revenue_month' => (float) DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->where('type', 'invoice')
                ->whereIn('status', ['validated', 'synced'])
                ->whereBetween('issue_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('total_excl_tax'),
        ];

        // ——— Volumes 6 mois (dossiers créés, par sens) ———
        $volumes = DB::table('shipments')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->selectRaw("to_char(date_trunc('month', created_at), 'YYYY-MM') AS month, direction, count(*) AS cnt")
            ->groupByRaw('1, 2')
            ->orderBy('month')
            ->get()
            ->groupBy('month')
            ->map(fn ($rows, $month) => [
                'month' => $month,
                'import' => (int) ($rows->firstWhere('direction', 'import')->cnt ?? 0),
                'export' => (int) ($rows->firstWhere('direction', 'export')->cnt ?? 0),
            ])->values();

        // ——— Alertes ———
        $alerts = [];

        foreach (DB::table('v_demurrage_alerts')->where('tenant_id', $tenantId)->where('days_remaining', '<=', 3)->limit(5)->get() as $row) {
            $reference = DB::table('shipments')->where('id', $row->shipment_id)->value('reference');
            $alerts[] = [
                'severity' => $row->days_remaining < 0 ? 'critical' : 'warning',
                'title' => $row->days_remaining < 0
                    ? "Franchise surestaries dépassée ({$row->container_number})"
                    : "Franchise surestaries expire dans {$row->days_remaining} j ({$row->container_number})",
                'context' => $reference,
                'shipment_id' => $row->shipment_id,
            ];
        }

        $cutoffs = DB::table('bookings')
            ->join('shipments', 'shipments.id', '=', 'bookings.shipment_id')
            ->where('bookings.tenant_id', $tenantId)
            ->whereIn('bookings.status', ['requested', 'confirmed'])
            ->where(function ($query): void {
                $window = [now(), now()->addHours(48)];
                $query->whereBetween('vgm_cutoff', $window)
                    ->orWhereBetween('doc_cutoff', $window)
                    ->orWhereBetween('port_cutoff', $window);
            })
            ->limit(5)
            ->get(['bookings.id', 'bookings.booking_number', 'bookings.vgm_cutoff', 'bookings.doc_cutoff', 'bookings.port_cutoff', 'shipments.reference', 'shipments.id AS shipment_id']);
        foreach ($cutoffs as $row) {
            $next = collect([['VGM', $row->vgm_cutoff], ['documentaire', $row->doc_cutoff], ['portuaire', $row->port_cutoff]])
                ->filter(fn ($c) => $c[1] !== null && $c[1] >= now()->toDateTimeString())
                ->sortBy(fn ($c) => $c[1])
                ->first();
            if ($next !== null) {
                $alerts[] = [
                    'severity' => 'warning',
                    'title' => "Cut-off {$next[0]} imminent — booking {$row->booking_number}",
                    'context' => $row->reference,
                    'shipment_id' => $row->shipment_id,
                ];
            }
        }

        foreach (DB::table('v_active_delays')->where('tenant_id', $tenantId)->orderByDesc('delay_days')->limit(5)->get() as $row) {
            $alerts[] = [
                'severity' => $row->delay_days >= 5 ? 'critical' : 'warning',
                'title' => "Retard +{$row->delay_days} j — nouvelle ETA ".substr((string) $row->eta, 0, 10),
                'context' => $row->reference,
                'shipment_id' => $row->id,
            ];
        }

        // ——— Dossiers récents (uuid v7 = tri temporel) ———
        $recent = $open->sortByDesc('id')->take(6)->values();

        return response()->json([
            'kpis' => $kpis,
            'volumes' => $volumes,
            'alerts' => array_slice($alerts, 0, 8),
            'recent_shipments' => $recent,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
