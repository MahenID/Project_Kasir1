<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization (open shift, permission) enforced in the service layer.
        return true;
    }

    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'string', 'uuid'],
            'payment_method' => ['required', 'string', Rule::in(['cash', 'debit', 'credit', 'qris', 'transfer'])],
            'tendered_amount' => ['nullable', 'integer', 'min:0'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'quote_id.required' => 'Kutipan checkout (quote_id) wajib disertakan.',
            'quote_id.uuid' => 'Format quote_id tidak valid.',
            'payment_method.required' => 'Metode pembayaran wajib dipilih.',
            'payment_method.in' => 'Metode pembayaran tidak didukung.',
            'tendered_amount.integer' => 'Jumlah uang tunai harus berupa angka bulat rupiah.',
            'tendered_amount.min' => 'Jumlah uang tunai tidak boleh negatif.',
            'idempotency_key.required' => 'Kunci idempotensi wajib disertakan untuk mencegah transaksi ganda.',
            'idempotency_key.min' => 'Kunci idempotensi terlalu pendek.',
        ];
    }

    /**
     * Canonical payload used for the durable idempotency hash. Only business
     * fields participate, so a retry with the same intent maps to the same hash.
     */
    public function canonicalPayload(): array
    {
        return [
            'quote_id' => $this->input('quote_id'),
            'payment_method' => $this->input('payment_method'),
            'tendered_amount' => $this->input('tendered_amount') !== null
                ? (int) $this->input('tendered_amount')
                : null,
            'payment_reference' => $this->input('payment_reference'),
        ];
    }
}
