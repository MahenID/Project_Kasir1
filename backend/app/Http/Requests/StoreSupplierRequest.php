<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25'],
            'email' => ['nullable', 'email', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode supplier wajib diisi.',
            'code.max' => 'Kode supplier maksimal 50 karakter.',
            'name.required' => 'Nama supplier wajib diisi.',
            'name.max' => 'Nama supplier maksimal 150 karakter.',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}
