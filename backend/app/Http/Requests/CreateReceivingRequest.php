<?php

namespace App\Http\Requests;

use App\Services\ReceivingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReceivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                ReceivingService::TYPE_PURCHASE,
                ReceivingService::TYPE_OPENING,
            ])],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'received_at' => ['nullable', 'date'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Tipe penerimaan wajib dipilih.',
            'type.in' => 'Tipe penerimaan tidak didukung.',
            'supplier_id.exists' => 'Supplier tidak ditemukan.',
            'items.*.product_id.exists' => 'Produk pada baris item tidak ditemukan.',
            'items.*.quantity.min' => 'Kuantitas item harus lebih besar dari nol.',
            'items.*.unit_cost.min' => 'Harga modal satuan tidak boleh negatif.',
        ];
    }
}
