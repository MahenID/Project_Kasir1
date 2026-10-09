<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprintSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Per-record access is enforced inside the controller via authorizeSaleAccess.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'Alasan cetak ulang maksimal 100 karakter.',
        ];
    }
}
