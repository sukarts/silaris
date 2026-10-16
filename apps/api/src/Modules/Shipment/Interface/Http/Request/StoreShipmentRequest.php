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
            'client_id' => ['required', 'uuid', tenantExists('parties')->where('type', 'client')],
            'branch_id' => ['required', 'uuid', tenantExists('branches')],
            'company_id' => ['required', 'uuid', tenantExists('companies')],
            'agent_id' => ['required', 'uuid', tenantExists('users')],
            'supervisor_id' => ['nullable', 'uuid', tenantExists('users')],
            // Repris de la cotation quand elle existe ; déclarés par
            // l'exploitant lors d'une ouverture dérogatoire.
            'direction' => ['required_without:quote_id', Rule::in(['import', 'export', 'transit'])],
            'mode' => ['required_without:quote_id', Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road', 'multimodal'])],
            'incoterm_code' => ['required_without:quote_id', 'size:3', 'exists:incoterms,code'],
            'origin_locode' => ['required_without:quote_id', 'size:5', 'regex:/^[A-Z]{2}[A-Z2-9]{3}$/'],
            'destination_locode' => ['required_without:quote_id', 'size:5', 'regex:/^[A-Z]{2}[A-Z2-9]{3}$/', 'different:origin_locode'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'critical'])],
            'etd' => ['nullable', 'date'],
            'eta' => ['nullable', 'date', 'after_or_equal:etd'],
            // Un dossier repose sur l'accord du client. À défaut, l'exploitant
            // motive l'urgence et la direction tranchera.
            'quote_id' => ['required_without:waiver_reason', 'nullable', 'uuid', tenantExists('quotes')],
            'waiver_reason' => ['required_without:quote_id', 'nullable', 'string', 'min:15', 'max:500'],
            'workflow_definition_id' => ['nullable', 'uuid', tenantExists('workflow_definitions')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
