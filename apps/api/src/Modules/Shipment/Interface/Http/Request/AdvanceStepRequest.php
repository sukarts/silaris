<?php

declare(strict_types=1);

namespace Silaris\Modules\Shipment\Interface\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

class AdvanceStepRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'next_step' => ['required', 'string', 'max:64'],
        ];
    }
}
