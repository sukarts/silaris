<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Application\Port\ReferenceGenerator;
use Silaris\Modules\Tenancy\Infrastructure\Persistence\Model\BranchModel;

/**
 * Référence dossier : {CODE_SOCIETE}-{ANNEE}-{SEQ:5}, séquence sans trou
 * par agence+année via next_sequence() (verrou ligne, même transaction).
 */
final readonly class SequenceReferenceGenerator implements ReferenceGenerator
{
    public function __construct(private TenantContext $tenant) {}

    public function nextShipmentReference(string $branchId): string
    {
        $branch = BranchModel::with('company')->findOrFail($branchId);
        $year = (int) date('Y');

        $next = DB::selectOne(
            'SELECT next_sequence(?, ?) AS value',
            [$this->tenant->id(), "shipment:{$branch->code}:{$year}"],
        )->value;

        return sprintf('%s-%d-%05d', $branch->company->code, $year, $next);
    }
}
