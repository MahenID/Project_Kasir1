<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define all permissions matching PRD Section 3 matrix
        $permissions = [
            // Settings & Terminals
            'manage-settings',
            'view-settings',

            // Users
            'manage-users',
            'manage-cashiers',

            // Catalog & Inventory Costs
            'view-products',
            'view-costs',
            'manage-catalog',

            // Customers
            'search-customers',
            'manage-customers',

            // Shifts & Cash Movements
            'operate-own-shift',
            'review-all-shifts',
            'record-cash-movement',

            // Sales & Checkout
            'checkout',
            'apply-item-discount',
            'apply-cart-discount',
            'view-all-sales',
            'view-own-sales',

            // Returns & Refunds
            'create-return-request',
            'approve-return-request',
            'process-refund',

            // Inventory Operations
            'manage-inventory',
            'revalue-inventory-cost',

            // Reports & Exports
            'view-all-reports',
            'view-own-reports',

            // Audit
            'view-all-audit-logs',
            'view-operational-audit-logs',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // 1. Role: owner
        $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
        $ownerRole->syncPermissions(Permission::all());

        // 2. Role: manager
        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions([
            'view-settings',
            'manage-cashiers',
            'view-products',
            'view-costs',
            'manage-catalog',
            'search-customers',
            'manage-customers',
            'operate-own-shift',
            'review-all-shifts',
            'record-cash-movement',
            'checkout',
            'apply-item-discount',
            'apply-cart-discount',
            'view-all-sales',
            'view-own-sales',
            'create-return-request',
            'approve-return-request',
            'process-refund',
            'manage-inventory',
            'view-all-reports',
            'view-own-reports',
            'view-operational-audit-logs',
        ]);

        // 3. Role: cashier
        $cashierRole = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $cashierRole->syncPermissions([
            'view-settings',
            'view-products',
            'search-customers',
            'operate-own-shift',
            'checkout',
            'apply-item-discount',
            'view-own-sales',
            'create-return-request',
            'process-refund',
            'view-own-reports',
        ]);
    }
}
