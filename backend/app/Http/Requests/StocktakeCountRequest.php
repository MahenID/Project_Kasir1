<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StocktakeCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'counts' => ['required_without:counted_stock', 'array'],
            'counts.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'counts.*.counted_stock' => ['required', 'integer', 'min:0'],
            'counted_stock' => ['required_without:counts', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'counts.*.counted_stock.min' => 'Jumlah fisik tidak boleh negatif.',
            'counted_stock.min' => 'Jumlah fisik tidak boleh negatif.',
        ];
    }
}
