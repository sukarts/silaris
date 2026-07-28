<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Application\Port\ReferenceGenerator;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\CompanyModel;

/**
 * Référence dossier : format choisi par le transitaire dans les paramètres
 * société (companies.shipment_settings), séquence sans trou par agence+année
 * via next_sequence() (verrou ligne, même transaction).
 *
 * Placeholders : {PREFIX} {COMPANY} {BRANCH} {DIRECTION} {YEAR} {YY} {MONTH} {SEQ:n}
 * Défaut historique : {COMPANY}-{YEAR}-{SEQ:5} → TAL-2026-00128
 *
 * {DIRECTION} rend IMP, EXP ou TRA. Dès qu'il figure au format, la séquence est
 * comptée par sens : sans cela la série IMP présenterait des trous à chaque
 * dossier export intercalé, ce qu'un contrôle fiscal lit comme une pièce
 * manquante.
 */
final readonly class SequenceReferenceGenerator implements ReferenceGenerator
{
    public const DEFAULT_FORMAT = '{COMPANY}-{YEAR}-{SEQ:5}';

    /** Sens du dossier tel qu'il apparaît dans la référence. */
    public const DIRECTION_CODES = ['import' => 'IMP', 'export' => 'EXP', 'transit' => 'TRA'];

    public function __construct(private TenantContext $tenant) {}

    public function nextShipmentReference(string $branchId, string $direction = 'import'): string
    {
        $branch = BranchModel::with('company')->findOrFail($branchId);
        /** @var CompanyModel $company */
        $company = $branch->company;
        $year = (int) date('Y');

        $settings = $company->shipment_settings ?? [];
        $format = (string) ($settings['reference_format'] ?? self::DEFAULT_FORMAT);
        $prefix = (string) ($settings['reference_prefix'] ?? $company->code);
        $directionCode = self::directionCode($direction);

        // Compteur par sens seulement lorsque le sens figure dans la référence,
        // pour que chaque série reste continue.
        $scope = str_contains($format, '{DIRECTION}') ? ":{$directionCode}" : '';
        $next = (int) DB::selectOne(
            'SELECT next_sequence(?, ?) AS value',
            [$this->tenant->id(), "shipment:{$branch->code}{$scope}:{$year}"],
        )->value;

        return self::render($format, [
            'PREFIX' => $prefix,
            'COMPANY' => (string) $company->code,
            'BRANCH' => (string) $branch->code,
            'DIRECTION' => $directionCode,
            'YEAR' => (string) $year,
            'YY' => date('y'),
            'MONTH' => date('m'),
        ], $next);
    }

    /** IMP, EXP ou TRA ; repli sur IMP pour un sens inconnu. */
    public static function directionCode(string $direction): string
    {
        return self::DIRECTION_CODES[$direction] ?? 'IMP';
    }

    /**
     * Substitue les placeholders ; {SEQ:n} pade la séquence sur n chiffres.
     *
     * @param  array<string, string>  $tokens
     */
    public static function render(string $format, array $tokens, int $sequence): string
    {
        $rendered = $format;
        foreach ($tokens as $key => $value) {
            $rendered = str_replace('{'.$key.'}', $value, $rendered);
        }

        return (string) preg_replace_callback(
            '/\{SEQ:(\d+)\}/',
            fn (array $m): string => str_pad((string) $sequence, (int) $m[1], '0', STR_PAD_LEFT),
            $rendered,
        );
    }
}
