<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListShipmentsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filter.status' => ['sometimes', 'array'],
            'filter.status.*' => ['string', 'max:64'],
            'filter.mode' => ['sometimes', 'array'],
            'filter.mode.*' => [Rule::in(['sea_fcl', 'sea_lcl', 'air', 'road', 'multimodal'])],
            'filter.direction' => ['sometimes', Rule::in(['import', 'export', 'transit'])],
            'filter.client_id' => ['sometimes', 'uuid'],
            'filter.agent_id' => ['sometimes', 'uuid'],
            'filter.delayed' => ['sometimes', 'boolean'],
            'filter.open' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['eta', '-eta', 'etd', '-etd', 'reference', '-reference'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }
}
