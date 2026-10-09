<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:50'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
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
            'name.required' => 'Nama supplier wajib diisi.',
            'email.email' => 'Format email tidak valid.',
        ];
    }
}
