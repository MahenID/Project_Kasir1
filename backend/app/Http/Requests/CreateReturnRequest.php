<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['owner', 'manager', 'cashier']) && $this->user()->can('create-return-request');
    }

    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'min:1', 'exists:sales,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.reason' => ['required', 'string', 'max:1000'],
            'lines.*.disposition' => ['required', Rule::in(['restockable', 'damaged'])],
        ];
    }
}
