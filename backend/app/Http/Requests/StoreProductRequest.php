<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'preferred_supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'unit' => ['nullable', 'string', 'max:30'],
            'selling_price' => ['required', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'file', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'sku.required' => 'SKU wajib diisi.',
            'category_id.required' => 'Kategori wajib dipilih.',
            'category_id.exists' => 'Kategori tidak ditemukan.',
            'selling_price.required' => 'Harga jual wajib diisi.',
            'selling_price.integer' => 'Harga jual harus dalam rupiah penuh (tanpa desimal).',
            'selling_price.min' => 'Harga jual tidak boleh negatif.',
            'image.max' => 'Gambar produk maksimal 5 MB.',
        ];
    }
}
