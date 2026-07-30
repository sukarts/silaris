<?php

declare(strict_types=1);

namespace Silaris\Modules\Air\Application\Service;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Air\Infrastructure\Persistence\Model\AirWaybillModel;

/**
 * Lettre de transport aérien (LTA / AWB) — document remis à la compagnie et
 * joint au dossier. Il porte l'expéditeur, le destinataire, le routing par
 * segments, les poids (dont le poids taxable IATA) et la nature de la
 * marchandise.
 *
 * La sous-traitance et les arrangements internes du transitaire n'y figurent
 * pas : la LTA suit la marchandise, pas le dossier commercial.
 */
final class AwbBuilder
{
    /**
     * @return array{awb: AirWaybillModel, airline: object|null, airports: array<string,string>, client: object|null, company_id: string}
     */
    public function build(AirWaybillModel $awb): array
    {
        $awb->loadMissing(['legs', 'airline', 'shipment']);

        $client = $awb->shipment === null ? null : DB::table('parties')
            ->where('id', $awb->shipment->client_id)
            ->first(['name', 'code']);

        return [
            'awb' => $awb,
            'airline' => $awb->airline,
            'airports' => $this->airportNames($awb),
            'client' => $client,
            'company_id' => $this->companyId($awb),
        ];
    }

    /**
     * Libellés d'aéroports touchés par les segments, indexés par code IATA :
     * la LTA nomme les escales, elle ne se contente pas des trois lettres.
     *
     * @return array<string,string>
     */
    private function airportNames(AirWaybillModel $awb): array
    {
        $codes = $awb->legs
            ->flatMap(fn ($leg) => [$leg->origin_iata, $leg->destination_iata])
            ->unique()
            ->all();

        if ($codes === []) {
            return [];
        }

        return DB::table('airports')
            ->whereIn('iata', $codes)
            ->pluck('name', 'iata')
            ->all();
    }

    /** Société émettrice : celle du dossier, sinon la seule société du tenant. */
    private function companyId(AirWaybillModel $awb): string
    {
        return $awb->shipment->company_id
            ?? (string) DB::table('companies')->orderBy('created_at')->value('id');
    }

    /**
     * Numéro LTA lisible : préfixe compagnie et numéro de série séparés d'un
     * tiret (057-12345675), comme sur le document IATA. Le HAWB reste tel quel.
     */
    public static function formatNumber(AirWaybillModel $awb): string
    {
        $number = (string) $awb->number;

        if ($awb->type === 'master' && strlen($number) === 11) {
            return substr($number, 0, 3).'-'.substr($number, 3);
        }

        return $number;
    }

    /** Nom de fichier stable, lisible dans une boîte mail comme dans un dossier. */
    public static function fileName(AirWaybillModel $awb): string
    {
        return 'lta-'.strtolower(str_replace([' ', '/'], '-', (string) $awb->number)).'.pdf';
    }
}
