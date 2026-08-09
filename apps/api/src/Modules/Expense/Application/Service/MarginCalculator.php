<?php

declare(strict_types=1);

namespace Silaris\Modules\Expense\Application\Service;

use Illuminate\Support\Facades\DB;

/**
 * Marge d'un dossier : la prévision de la cotation face au réalisé.
 *
 * Prévisionnel — la cotation acceptée porte son prix de vente (total_amount) et
 * son coût estimé (total_buy_amount). Réel — le chiffre d'affaires facturé (net
 * des avoirs) face aux dépenses fournisseurs engagées. Tout est hors taxe, pour
 * comparer ce qui se compare.
 */
final class MarginCalculator
{
    /** Dépenses comptées dans le coût réel : ni brouillon de saisie, ni annulée. */
    private const REAL_COST_STATUSES = ['validated', 'paid'];

    /**
     * @return array<string, mixed>
     */
    public function forShipment(string $shipmentId): array
    {
        $shipment = DB::table('shipments')->where('id', $shipmentId)->first(['id', 'quote_id', 'agent_id']);

        $forecast = $this->forecast($shipment->quote_id ?? null);
        $realRevenue = $this->realRevenue($shipmentId);
        $realCost = $this->expenseTotal($shipmentId, self::REAL_COST_STATUSES);
        $pendingCost = $this->expenseTotal($shipmentId, ['recorded']);
        $realMargin = round($realRevenue - $realCost, 2);

        return [
            'forecast' => $forecast,
            'real' => [
                'revenue' => round($realRevenue, 2),
                'cost' => round($realCost, 2),
                'pending_cost' => round($pendingCost, 2),
                'margin' => $realMargin,
                'rate' => self::rate($realRevenue, $realCost),
            ],
            // Écart de marge : positif = mieux que prévu, négatif = dérapage.
            'variance' => round($realMargin - (float) $forecast['margin'], 2),
        ];
    }

    /**
     * @return array{sell: float, cost: float, margin: float, rate: float}
     */
    private function forecast(?string $quoteId): array
    {
        $quote = $quoteId === null ? null : DB::table('quotes')->where('id', $quoteId)->first(['total_amount', 'total_buy_amount']);
        $sell = (float) ($quote->total_amount ?? 0);
        $cost = (float) ($quote->total_buy_amount ?? 0);

        return [
            'sell' => round($sell, 2),
            'cost' => round($cost, 2),
            'margin' => round($sell - $cost, 2),
            'rate' => self::rate($sell, $cost),
        ];
    }

    /** CA facturé du dossier, net des avoirs (mêmes règles que le reporting). */
    private function realRevenue(string $shipmentId): float
    {
        $signed = "coalesce(sum(case when type = 'credit_note' then -total_excl_tax else total_excl_tax end), 0)";

        return (float) DB::table('invoices')
            ->where('shipment_id', $shipmentId)
            ->whereIn('type', ['invoice', 'credit_note'])
            ->whereIn('status', ['validated', 'synced'])
            ->selectRaw("{$signed} AS net")
            ->value('net');
    }

    /**
     * @param  list<string>  $statuses
     */
    private function expenseTotal(string $shipmentId, array $statuses): float
    {
        return (float) DB::table('expenses')
            ->where('shipment_id', $shipmentId)
            ->whereIn('status', $statuses)
            ->sum('amount');
    }

    private static function rate(float $revenue, float $cost): float
    {
        return $revenue <= 0.0 ? 0.0 : round(($revenue - $cost) / $revenue * 100, 1);
    }
}
