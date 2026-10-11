<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasAnyRole(['owner', 'manager', 'cashier']) && $this->user()->can('process-refund');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key') ?? $this->input('idempotency_key')]);
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', 'min:1'],
            'confirmed_cash_amount' => ['nullable', 'integer', 'min:0'],
            'refund_reference' => ['nullable', 'string', 'max:255'],
            'non_cash_confirmed' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'uuid'],
            'return_request_id' => ['prohibited'],
        ];
    }

    public function canonicalPayload(int $requestId): array
    {
        return [
            'return_request_id' => $requestId,
            'shift_id' => (int) $this->input('shift_id'),
            'confirmed_cash_amount' => $this->input('confirmed_cash_amount') === null ? null : (int) $this->input('confirmed_cash_amount'),
            'refund_reference' => $this->input('refund_reference'),
            'non_cash_confirmed' => (bool) $this->input('non_cash_confirmed', false),
        ];
    }
}
