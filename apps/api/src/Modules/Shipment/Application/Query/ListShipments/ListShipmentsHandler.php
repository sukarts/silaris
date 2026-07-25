<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Application\Query\ListShipments;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Silaris\Modules\Shared\Infrastructure\Auth\CurrentUser;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;

/** Lecture via la vue v_shipments_list — pas d'agrégat, read model direct. */
final readonly class ListShipmentsHandler
{
    public function __construct(
        private TenantContext $tenant,
        private CurrentUser $currentUser,
    ) {}

    public function handle(ListShipmentsQuery $query): CursorPaginator
    {
        $q = DB::table('v_shipments_list')->where('tenant_id', $this->tenant->id());

        // Scope agences : direction/admin voient tout, les autres leurs agences affectées.
        if ($this->currentUser->isSet() && ($branchIds = $this->currentUser->branchScope()) !== null) {
            $q->whereIn('branch_code', DB::table('branches')->whereIn('id', $branchIds)->pluck('code'));
        }

        $f = $query->filters;
        if (isset($f['status'])) {
            $q->whereIn('status', (array) $f['status']);
        }
        if (isset($f['mode'])) {
            $q->whereIn('mode', (array) $f['mode']);
        }
        if (isset($f['direction'])) {
            $q->where('direction', $f['direction']);
        }
        if (isset($f['client_id'])) {
            $q->where('client_id', $f['client_id']);
        }
        if (isset($f['agent_id'])) {
            $q->where('agent_id', $f['agent_id']);
        }
        if (($f['delayed'] ?? false) === true) {
            $q->where('is_delayed', true);
        }
        if (($f['open'] ?? true) === true) {
            $q->whereNull('closed_at');
        }
        if (isset($f['search'])) {
            $q->where(fn ($w) => $w
                ->whereLike('reference', "%{$f['search']}%")
                ->orWhereLike('client_name', "%{$f['search']}%"));
        }

        $direction = str_starts_with($query->sort, '-') ? 'desc' : 'asc';
        $column = ltrim($query->sort, '-');
        if (! in_array($column, ['eta', 'etd', 'reference'], true)) {
            $column = 'eta';
        }

        return $q->orderBy($column, $direction)->orderBy('id')
            ->cursorPaginate($query->perPage, ['*'], 'cursor', $query->cursor);
    }
}
