<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\User;

/**
 * Customer master data (FR-CUST-01) — Fase 4.A.
 *
 * Cashiers may search and attach customers to sales; only owner/manager
 * (manage-customers) may edit or archive them. No loyalty/points fields.
 */
class CustomerService
{
    /**
     * @param  array{code?: string, name: string, phone?: ?string, email?: ?string, address?: ?string, is_active?: bool}  $data
     */
    public function create(User $user, array $data): Customer
    {
        // Cashiers may create a customer during checkout; editing later is privileged.
        if (!($user->can('search-customers') || $user->can('manage-customers'))) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk menambah pelanggan.',
                'CUSTOMER_FORBIDDEN',
                403,
            );
        }

        $customer = new Customer();
        $customer->code = $data['code'] ?? $this->nextCode();
        $this->assertCodeAvailable($customer->code);
        $customer->name = $data['name'];
        $customer->phone = $data['phone'] ?? null;
        $customer->email = $data['email'] ?? null;
        $customer->address = $data['address'] ?? null;
        $customer->is_active = $data['is_active'] ?? true;
        $customer->save();

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Customer $customer, array $data): Customer
    {
        $this->authorizeManage($user);

        if (array_key_exists('code', $data) && $data['code'] !== $customer->code) {
            $this->assertCodeAvailable($data['code']);
            $customer->code = $data['code'];
        }

        foreach (['name', 'phone', 'email', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                $customer->{$field} = $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $customer->is_active = (bool) $data['is_active'];
        }

        $customer->save();

        return $customer;
    }

    public function archive(User $user, Customer $customer): Customer
    {
        return $this->update($user, $customer, ['is_active' => false]);
    }

    public function buildCustomerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'address' => $customer->address,
            'is_active' => $customer->is_active,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @throws DomainException
     */
    public function authorizeManage(User $user): void
    {
        if (!($user->can('manage-customers') || $user->hasAnyRole(['owner', 'manager']))) {
            throw new DomainException(
                'Hanya owner/manager yang dapat mengubah atau mengarsipkan pelanggan.',
                'CUSTOMER_FORBIDDEN',
                403,
            );
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertCodeAvailable(string $code): void
    {
        if (Customer::where('code', $code)->exists()) {
            throw new DomainException(
                "Kode pelanggan '{$code}' sudah dipakai.",
                'CUSTOMER_CODE_TAKEN',
                409,
                ['code' => $code],
            );
        }
    }

    /**
     * Sequential customer code: CUST-001, CUST-002, ...
     */
    protected function nextCode(): string
    {
        $latest = Customer::where('code', 'like', 'CUST-%')
            ->orderByDesc('id')
            ->value('code');

        $next = 1;
        if ($latest && preg_match('/^CUST-(\d+)$/', $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        }

        $code = 'CUST-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        while (Customer::where('code', $code)->exists()) {
            $next++;
            $code = 'CUST-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        }

        return $code;
    }
}
