<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReceivingLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Setidaknya satu baris item harus dikirim.',
            'items.*.product_id.exists' => 'Produk pada baris item tidak ditemukan.',
            'items.*.quantity.min' => 'Kuantitas item harus lebih besar dari nol.',
            'items.*.unit_cost.min' => 'Harga modal satuan tidak boleh negatif.',
        ];
    }
}
