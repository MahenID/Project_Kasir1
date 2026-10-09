<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartStocktakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.max' => 'Catatan stocktake maksimal 1000 karakter.',
        ];
    }
}
