<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Owner
        $owner = User::firstOrCreate(
            ['email' => 'owner@dragonmart.local'],
            [
                'name' => 'Owner DragonMart',
                'password' => Hash::make('Password123!'),
                'phone' => '081122334455',
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $owner->syncRoles(['owner']);

        // 2. Manager
        $manager = User::firstOrCreate(
            ['email' => 'manager@dragonmart.local'],
            [
                'name' => 'Manager Toko',
                'password' => Hash::make('Password123!'),
                'phone' => '081122334466',
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
        $manager->syncRoles(['manager']);

        // 3. Cashiers (including accounts migrated from kasirdragon legacy)
        $cashiers = [
            [
                'name' => 'Mahendra',
                'email' => 'mahengon@gmail.com',
                'phone' => '081330362741',
            ],
            [
                'name' => 'Deadi',
                'email' => 'munyukgelok@gmail.com',
                'phone' => '081330362742',
            ],
            [
                'name' => 'Alexandro',
                'email' => 'sandro17@gmail.com',
                'phone' => '081330362743',
            ],
            [
                'name' => 'Marsel',
                'email' => 'marselfb@gmail.com',
                'phone' => '081330362744',
            ],
        ];

        foreach ($cashiers as $cashierData) {
            $cashier = User::firstOrCreate(
                ['email' => $cashierData['email']],
                [
                    'name' => $cashierData['name'],
                    'password' => Hash::make('Password123!'),
                    'phone' => $cashierData['phone'],
                    'is_active' => true,
                    'must_change_password' => false,
                ]
            );
            $cashier->syncRoles(['cashier']);
        }
    }
}
