<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShipmentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid', Rule::exists('parties', 'id')->where('type', 'client')],
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'company_id' => ['required', 'uuid', 'exists:companies,id'],
            'agent_id' => ['required', 'uuid', 'exists:users,id'],
            'supervisor_id' => ['nullable', 'uuid', 'exists:users,id'],
            // Mode, sens, incoterm et ports sont repris de la cotation acceptée :
            // acceptés ici par tolérance, ils n'ont pas autorité.
            'direction' => ['sometimes', Rule::in(['import', 'export', 'transit'])],
            'mode' => ['sometimes', Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road', 'multimodal'])],
            'incoterm_code' => ['sometimes', 'size:3', 'exists:incoterms,code'],
            'origin_locode' => ['sometimes', 'size:5', 'regex:/^[A-Z]{2}[A-Z2-9]{3}$/'],
            'destination_locode' => ['sometimes', 'size:5', 'regex:/^[A-Z]{2}[A-Z2-9]{3}$/', 'different:origin_locode'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'critical'])],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:etd'],
            // Un dossier n'existe que sur accord préalable du client.
            'quote_id' => ['required', 'uuid', 'exists:quotes,id'],
            'workflow_definition_id' => ['nullable', 'uuid', 'exists:workflow_definitions,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
