<?php

declare(strict_types=1);

namespace Silaris\Modules\Billing\Domain\Service;

/**
 * Ce qu'un client doit encore, et depuis quand.
 *
 * Le recouvrement ne se joue pas sur le total dû mais sur son ancienneté :
 * une créance de trente jours se relance, une de cent vingt se provisionne.
 * D'où le classement par tranches, qui est la lecture qu'en fait un
 * responsable financier.
 *
 * Le calcul est volontairement séparé de la base : il doit pouvoir être
 * éprouvé sur des cas limites — règlement au centime près, avoir supérieur à
 * la facture, échéance du jour même — sans monter un jeu de données.
 */
final class ReceivableBalance
{
    /** Bornes en jours de retard. La dernière tranche est ouverte. */
    public const BUCKETS = [30, 60, 90];

    /**
     * Reste dû d'une facture, jamais négatif : un trop-perçu est un fait de
     * caisse, pas une créance négative, et se solde par un avoir.
     */
    public static function outstanding(float $total, float $allocated): float
    {
        return round(max(0.0, $total - $allocated), 2);
    }

    /**
     * État de paiement déduit, jamais déclaré.
     *
     * La comparaison se fait au centime : additionner des décimaux venus de la
     * base peut laisser un écart infime qu'un test d'égalité stricte lirait
     * comme un solde impayé de 0,00.
     */
    public static function status(float $total, float $allocated): string
    {
        if ($allocated <= 0.0049) {
            return 'unpaid';
        }

        return $allocated + 0.0049 >= $total ? 'paid' : 'partial';
    }

    /**
     * Ancienneté d'une créance à une date donnée. Une facture non échue tombe
     * dans la tranche « courant », qui n'est pas du retard.
     *
     * @return 'current'|'1_30'|'31_60'|'61_90'|'over_90'
     */
    public static function bucket(\DateTimeImmutable $dueDate, \DateTimeImmutable $asOf): string
    {
        $days = (int) $dueDate->diff($asOf)->format('%r%a');

        return match (true) {
            $days <= 0 => 'current',
            $days <= self::BUCKETS[0] => '1_30',
            $days <= self::BUCKETS[1] => '31_60',
            $days <= self::BUCKETS[2] => '61_90',
            default => 'over_90',
        };
    }

    /**
     * Répartit les créances par tranche d'ancienneté.
     *
     * @param  list<array{due_date: \DateTimeImmutable, outstanding: float}>  $receivables
     * @return array{current: float, '1_30': float, '31_60': float, '61_90': float, over_90: float, total: float}
     */
    public static function aged(array $receivables, \DateTimeImmutable $asOf): array
    {
        $buckets = ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '61_90' => 0.0, 'over_90' => 0.0];

        foreach ($receivables as $receivable) {
            $buckets[self::bucket($receivable['due_date'], $asOf)] += $receivable['outstanding'];
        }

        $buckets = array_map(static fn (float $sum): float => round($sum, 2), $buckets);
        $buckets['total'] = round(array_sum($buckets), 2);

        return $buckets;
    }

    /**
     * Imputation au plus ancien : c'est l'usage, et c'est aussi ce qui limite
     * l'ancienneté moyenne de la créance. Rend les imputations à écrire et le
     * reliquat non imputé — un client peut payer d'avance.
     *
     * @param  list<array{invoice_id: string, outstanding: float}>  $outstanding  Du plus ancien au plus récent
     * @return array{allocations: list<array{invoice_id: string, amount: float}>, unallocated: float}
     */
    public static function allocateOldestFirst(float $amount, array $outstanding): array
    {
        $remaining = round($amount, 2);
        $allocations = [];

        foreach ($outstanding as $invoice) {
            if ($remaining <= 0.0049) {
                break;
            }

            $share = round(min($remaining, $invoice['outstanding']), 2);
            if ($share <= 0.0049) {
                continue;
            }

            $allocations[] = ['invoice_id' => $invoice['invoice_id'], 'amount' => $share];
            $remaining = round($remaining - $share, 2);
        }

        return ['allocations' => $allocations, 'unallocated' => max(0.0, $remaining)];
    }
}
