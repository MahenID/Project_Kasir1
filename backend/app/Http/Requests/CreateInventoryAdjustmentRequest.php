<?php

namespace App\Http\Requests;

use App\Services\InventoryAdjustmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([
                InventoryAdjustmentService::TYPE_QUANTITY,
                InventoryAdjustmentService::TYPE_REVALUATION,
            ])],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'evidence_reference' => ['nullable', 'string', 'max:150'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity_delta' => [
                Rule::requiredIf(fn () => $this->input('type') === InventoryAdjustmentService::TYPE_QUANTITY),
                'nullable',
                'integer',
            ],
            'items.*.new_average_cost' => [
                Rule::requiredIf(fn () => $this->input('type') === InventoryAdjustmentService::TYPE_REVALUATION),
                'nullable',
                'numeric',
                'min:0',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Tipe penyesuaian wajib dipilih.',
            'type.in' => 'Tipe penyesuaian tidak didukung.',
            'reason.required' => 'Alasan penyesuaian wajib diisi.',
            'reason.min' => 'Alasan penyesuaian minimal 5 karakter.',
            'items.required' => 'Setidaknya satu baris item harus dikirim.',
            'items.*.product_id.exists' => 'Produk pada baris item tidak ditemukan.',
            'items.*.new_average_cost.min' => 'Harga modal baru tidak boleh negatif.',
        ];
    }
}
