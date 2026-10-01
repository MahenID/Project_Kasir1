<?php

namespace Database\Seeders;

use App\Models\Terminal;
use Illuminate\Database\Seeder;

class TerminalSeeder extends Seeder
{
    public function run(): void
    {
        $terminals = [
            [
                'code' => 'TERM-01',
                'name' => 'Terminal Kasir 01',
                'is_active' => true,
            ],
            [
                'code' => 'TERM-02',
                'name' => 'Terminal Kasir 02',
                'is_active' => true,
            ],
        ];

        foreach ($terminals as $term) {
            Terminal::firstOrCreate(['code' => $term['code']], $term);
        }
    }
}
