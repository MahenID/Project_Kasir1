<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Supplier;
use App\Models\User;

/**
 * Supplier master data (FR-SUPP-01) — Fase 4.A.
 *
 * Preferred supplier on a product is a hint only; receiving history is
 * never constrained by it. Archival is blocked while the supplier still
 * has open (non-posted, non-cancelled) receiving documents.
 */
class SupplierService
{
    /**
     * @param  array{code: string, name: string, contact_person?: ?string, phone?: ?string, email?: ?string, address?: ?string, is_active?: bool}  $data
     */
    public function create(User $user, array $data): Supplier
    {
        $this->authorizeManage($user);

        $this->assertCodeAvailable($data['code']);

        $supplier = new Supplier();
        $supplier->code = $data['code'];
        $supplier->name = $data['name'];
        $supplier->contact_person = $data['contact_person'] ?? null;
        $supplier->phone = $data['phone'] ?? null;
        $supplier->email = $data['email'] ?? null;
        $supplier->address = $data['address'] ?? null;
        $supplier->is_active = $data['is_active'] ?? true;
        $supplier->save();

        return $supplier;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Supplier $supplier, array $data): Supplier
    {
        $this->authorizeManage($user);

        if (array_key_exists('code', $data) && $data['code'] !== $supplier->code) {
            $this->assertCodeAvailable($data['code']);
            $supplier->code = $data['code'];
        }

        foreach (['name', 'contact_person', 'phone', 'email', 'address'] as $field) {
            if (array_key_exists($field, $data)) {
                $supplier->{$field} = $data[$field];
            }
        }

        if (array_key_exists('is_active', $data)) {
            $supplier->is_active = (bool) $data['is_active'];
        }

        $supplier->save();

        return $supplier;
    }

    public function archive(User $user, Supplier $supplier): Supplier
    {
        return $this->update($user, $supplier, ['is_active' => false]);
    }

    public function buildSupplierPayload(Supplier $supplier): array
    {
        return [
            'id' => $supplier->id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'contact_person' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'is_active' => $supplier->is_active,
            'created_at' => $supplier->created_at?->toIso8601String(),
            'updated_at' => $supplier->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @throws DomainException
     */
    public function authorizeManage(User $user): void
    {
        if (!($user->can('manage-catalog') || $user->hasAnyRole(['owner', 'manager']))) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk mengelola supplier.',
                'CATALOG_FORBIDDEN',
                403,
            );
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertCodeAvailable(string $code): void
    {
        if (Supplier::where('code', $code)->exists()) {
            throw new DomainException(
                "Kode supplier '{$code}' sudah dipakai.",
                'SUPPLIER_CODE_TAKEN',
                409,
                ['code' => $code],
            );
        }
    }
}
