<?php

declare(strict_types=1);

namespace Silaris\Modules\Search\Interface\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Silaris\Modules\Billing\Infrastructure\Persistence\Model\InvoiceModel;
use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\BookingModel;
use Silaris\Modules\Ocean\Infrastructure\Persistence\Model\ContainerModel;
use Silaris\Modules\Shared\Infrastructure\Tenancy\TenantContext;
use Silaris\Modules\Shipment\Infrastructure\Persistence\Model\ShipmentModel;

/**
 * Recherche globale (palette ⌘K) : interroge les index Meilisearch par type,
 * scopée tenant (filtre index + scopes Eloquent à l'hydratation) et filtrée
 * par les permissions du demandeur — un groupe n'apparaît que si l'utilisateur
 * peut lire le module correspondant.
 */
class SearchController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']]);
        $q = $data['q'];
        $tenantFilter = 'tenant_id = "'.$this->tenant->id().'"';
        $groups = [];

        if (Gate::allows('shipments.read')) {
            $groups['shipments'] = ShipmentModel::search($q, fn ($meili, $query, $options) => $meili->search($query, array_merge($options, ['filter' => $tenantFilter, 'limit' => 5])))
                ->take(5)->get()
                ->map(fn (ShipmentModel $s) => [
                    'id' => $s->id, 'label' => $s->reference,
                    'sub' => trim(($s->origin_locode ?? '').' → '.($s->destination_locode ?? ''), ' →'),
                    'url' => "/shipments/{$s->id}",
                ])->values();
        }
        if (Gate::allows('crm.read')) {
            $groups['parties'] = PartyModel::search($q, fn ($meili, $query, $options) => $meili->search($query, array_merge($options, ['filter' => $tenantFilter, 'limit' => 5])))
                ->take(5)->get()
                ->map(fn (PartyModel $p) => [
                    'id' => $p->id, 'label' => $p->name, 'sub' => $p->code, 'url' => '/crm',
                ])->values();
        }
        if (Gate::allows('containers.read')) {
            $groups['containers'] = ContainerModel::search($q, fn ($meili, $query, $options) => $meili->search($query, array_merge($options, ['filter' => $tenantFilter, 'limit' => 5])))
                ->take(5)->get()
                ->map(fn (ContainerModel $c) => [
                    'id' => $c->id, 'label' => $c->number, 'sub' => $c->size_type ?? null, 'url' => '/containers',
                ])->values();
        }
        if (Gate::allows('bookings.read')) {
            $groups['bookings'] = BookingModel::search($q, fn ($meili, $query, $options) => $meili->search($query, array_merge($options, ['filter' => $tenantFilter, 'limit' => 5])))
                ->take(5)->get()
                ->map(fn (BookingModel $b) => [
                    'id' => $b->id, 'label' => $b->booking_number, 'sub' => null, 'url' => '/bookings',
                ])->values();
        }
        if (Gate::allows('invoices.read')) {
            $groups['invoices'] = InvoiceModel::search($q, fn ($meili, $query, $options) => $meili->search($query, array_merge($options, ['filter' => $tenantFilter, 'limit' => 5])))
                ->take(5)->get()
                ->map(fn (InvoiceModel $i) => [
                    'id' => $i->id, 'label' => $i->number, 'sub' => null, 'url' => '/billing',
                ])->values();
        }

        return response()->json(['query' => $q, 'groups' => $groups]);
    }
}
