<?php

namespace Database\Seeders;

use App\Models\StoreSetting;
use Illuminate\Database\Seeder;

class StoreSettingSeeder extends Seeder
{
    public function run(): void
    {
        StoreSetting::firstOrCreate(
            ['id' => 1],
            [
                'store_name' => 'DragonMart POS',
                'address' => 'Jl. Gatot Subroto No. 88, Denpasar, Bali',
                'phone' => '081234567890',
                'email' => 'store@dragonmart.local',
                'receipt_footer' => "Terima kasih telah berbelanja di DragonMart!\nBarang yang sudah dibeli dapat ditukar maksimal 7 hari dengan struk asli.",
                'timezone' => 'Asia/Makassar',
                'tax_enabled' => false, // Default tax disabled, can be activated
                'tax_rate_percent' => 11.00,
                'max_cashier_discount_percent' => 10.00,
                'return_window_days' => 7,
                'settings_version' => 1,
            ]
        );
    }
}
