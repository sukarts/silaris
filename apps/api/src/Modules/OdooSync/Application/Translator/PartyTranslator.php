<?php

declare(strict_types=1);

namespace Silaris\Modules\OdooSync\Application\Translator;

use Silaris\Modules\Crm\Infrastructure\Persistence\Model\PartyModel;

/** parties SILARIS → res.partner Odoo. */
final class PartyTranslator
{
    /** @return array<string, mixed> */
    public function toOdoo(PartyModel $party): array
    {
        $address = $party->addresses->firstWhere('is_default', true) ?? $party->addresses->first();
        $contact = $party->contacts->firstWhere('is_primary', true) ?? $party->contacts->first();

        return [
            'name' => $party->name,
            'ref' => $party->code,
            'is_company' => $party->kind !== 'individual',
            'customer_rank' => $party->type === 'client' ? 1 : 0,
            'supplier_rank' => $party->type === 'supplier' ? 1 : 0,
            'vat' => $party->tax_id,
            'email' => $contact?->email,
            'phone' => $contact?->phone,
            'street' => $address?->line1,
            'city' => $address?->city,
            'zip' => $address?->postal_code,
            'comment' => 'Synchronisé depuis SILARIS',
        ];
    }

    public function checksum(PartyModel $party): string
    {
        return hash('sha256', json_encode($this->toOdoo($party)));
    }
}
