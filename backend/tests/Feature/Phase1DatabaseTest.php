<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\StoreSetting;
use App\Models\Supplier;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase1DatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_populate_baseline_and_legacy_data(): void
    {
        $this->seed(DatabaseSeeder::class);

        // 1. Roles and Users
        $this->assertDatabaseCount('roles', 3);
        $this->assertTrue(Role::where('name', 'owner')->exists());
        $this->assertTrue(Role::where('name', 'manager')->exists());
        $this->assertTrue(Role::where('name', 'cashier')->exists());

        $owner = User::where('email', 'owner@dragonmart.local')->first();
        $this->assertNotNull($owner);
        $this->assertTrue($owner->hasRole('owner'));

        $cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->assertNotNull($cashier);
        $this->assertTrue($cashier->hasRole('cashier'));

        // 2. Store settings and terminals
        $setting = StoreSetting::first();
        $this->assertNotNull($setting);
        $this->assertEquals('DragonMart POS', $setting->store_name);
        $this->assertEquals('Asia/Makassar', $setting->timezone);

        $this->assertDatabaseCount('terminals', 2);
        $this->assertTrue(Terminal::where('code', 'TERM-01')->exists());

        // 3. Categories, Suppliers, Customers migrated from kasirdragon.sql
        $this->assertDatabaseCount('categories', 6);
        $this->assertDatabaseCount('suppliers', 5);
        $this->assertDatabaseCount('customers', 3);

        $this->assertTrue(Supplier::where('code', 'SUP001')->exists());
        $this->assertTrue(Category::where('slug', 'buku-tulis')->exists());
        $this->assertTrue(Customer::where('code', 'CUST-001')->exists());

        // 4. Products and Opening Balances
        $this->assertDatabaseCount('products', 6);
        $buku = Product::where('sku', 'BRG001')->first();
        $this->assertNotNull($buku);
        $this->assertEquals(75, $buku->stock);
        $this->assertEquals(5000, $buku->selling_price);
        $this->assertEquals('3500.000000', (string) $buku->average_cost);

        // 5. Receiving and Stock Movement Ledger
        $this->assertDatabaseCount('receivings', 1);
        $this->assertDatabaseCount('receiving_items', 6);
        $this->assertDatabaseCount('stock_movements', 6);

        $movement = StockMovement::where('product_id', $buku->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals('receiving_opening', $movement->type);
        $this->assertEquals(75, $movement->quantity);
        $this->assertEquals(0, $movement->stock_before);
        $this->assertEquals(75, $movement->stock_after);
        $this->assertEquals('3500.000000', (string) $movement->cost_after);
    }

    public function test_open_shift_uniqueness_per_user_is_enforced(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'mahengon@gmail.com')->first();
        $terminal = Terminal::where('code', 'TERM-01')->first();

        // First open shift succeeds
        Shift::create([
            'shift_number' => 'SHF-TEST-001',
            'user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 100000,
        ]);

        // Second open shift for the SAME user must throw unique constraint exception
        $this->expectException(QueryException::class);

        Shift::create([
            'shift_number' => 'SHF-TEST-002',
            'user_id' => $user->id,
            'terminal_id' => $terminal->id,
            'status' => 'open',
            'opened_at' => now(),
            'starting_cash' => 100000,
        ]);
    }
}
