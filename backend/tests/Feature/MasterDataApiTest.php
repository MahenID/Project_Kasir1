<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $manager;
    protected User $cashier;
    protected Category $category;
    protected Supplier $supplier;
    protected Product $buku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->owner = User::where('email', 'owner@dragonmart.local')->first();
        $this->manager = User::where('email', 'manager@dragonmart.local')->first();
        $this->cashier = User::where('email', 'mahengon@gmail.com')->first();
        $this->category = Category::where('slug', 'buku-tulis')->first();
        $this->supplier = Supplier::where('code', 'SUP001')->first();
        $this->buku = Product::where('sku', 'BRG001')->first();
    }

    /**
     * A real 2x2 PNG built once via GD so content-based validation passes.
     */
    protected function validPng(): UploadedFile
    {
        $im = imagecreatetruecolor(2, 2);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return UploadedFile::fake()->createWithContent('foto.png', $bytes);
    }

    // -------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------

    public function test_manager_can_create_category_with_generated_slug(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/categories', [
            'name' => 'Minuman Kaleng',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => ['name' => 'Minuman Kaleng', 'slug' => 'minuman-kaleng', 'is_active' => true],
            ]);
    }

    public function test_duplicate_category_name_gets_unique_slug(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/categories', ['name' => 'Minuman Kaleng'])->assertStatus(201);
        $this->actingAs($this->manager)->postJson('/api/v1/categories', ['name' => 'Minuman Kaleng'])
            ->assertStatus(201)
            ->assertJson(['data' => ['slug' => 'minuman-kaleng-2']]);
    }

    public function test_cashier_cannot_create_category(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/v1/categories', ['name' => 'Uji'])
            ->assertStatus(403)
            ->assertJson(['error' => ['code' => 'CATALOG_FORBIDDEN']]);
    }

    public function test_archive_blocked_while_active_products_remain(): void
    {
        $this->actingAs($this->manager)
            ->postJson("/api/v1/categories/{$this->category->id}/archive")
            ->assertStatus(409)
            ->assertJson(['error' => ['code' => 'CATEGORY_IN_USE']]);

        $this->assertTrue($this->category->fresh()->is_active);
    }

    public function test_archive_allowed_when_only_inactive_products_remain(): void
    {
        Product::where('category_id', $this->category->id)->update(['is_active' => false]);

        $this->actingAs($this->manager)
            ->postJson("/api/v1/categories/{$this->category->id}/archive")
            ->assertStatus(200)
            ->assertJson(['data' => ['is_active' => false]]);
    }

    public function test_cashier_can_list_categories(): void
    {
        $this->actingAs($this->cashier)->getJson('/api/v1/categories')
            ->assertStatus(200)
            ->assertJsonCount(6, 'data');
    }

    // -------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------

    public function test_manager_can_create_product_with_zero_stock_and_cost(): void
    {
        $response = $this->actingAs($this->manager)->postJson('/api/v1/products', [
            'sku' => 'BRG-NEW-01',
            'name' => 'Sabun Batang Baru',
            'category_id' => $this->category->id,
            'selling_price' => 4500,
            'min_stock' => 5,
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'data' => [
                    'sku' => 'BRG-NEW-01',
                    'stock' => 0,
                    'average_cost' => '0.000000',
                    'selling_price' => 4500,
                    'is_low_stock' => true,
                ],
            ]);
    }

    public function test_create_payload_cannot_set_stock_or_cost(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/products', [
            'sku' => 'BRG-NEW-02',
            'name' => 'Produk Nakal',
            'category_id' => $this->category->id,
            'selling_price' => 1000,
            'stock' => 9999,
            'average_cost' => '5000.000000',
        ])->assertStatus(201);

        $product = Product::where('sku', 'BRG-NEW-02')->firstOrFail();
        $this->assertSame(0, $product->stock);
        $this->assertSame('0.000000', (string) $product->average_cost);
    }

    public function test_duplicate_sku_and_barcode_rejected(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/products', [
            'sku' => 'BRG001',
            'name' => 'Duplikat',
            'category_id' => $this->category->id,
            'selling_price' => 100,
        ])->assertStatus(409)->assertJson(['error' => ['code' => 'PRODUCT_SKU_TAKEN']]);

        $withBarcode = Product::whereNotNull('barcode')->first();
        if ($withBarcode) {
            $this->actingAs($this->manager)->postJson('/api/v1/products', [
                'sku' => 'BRG-BARCODE-1',
                'barcode' => $withBarcode->barcode,
                'name' => 'Barcode Bentrok',
                'category_id' => $this->category->id,
                'selling_price' => 100,
            ])->assertStatus(409)->assertJson(['error' => ['code' => 'PRODUCT_BARCODE_TAKEN']]);
        }
    }

    public function test_cashier_cannot_create_product_and_never_sees_cost(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/v1/products', [
            'sku' => 'BRG-CASH-1',
            'name' => 'Dari Kasir',
            'category_id' => $this->category->id,
            'selling_price' => 100,
        ])->assertStatus(403)->assertJson(['error' => ['code' => 'CATALOG_FORBIDDEN']]);

        $list = $this->actingAs($this->cashier)->getJson('/api/v1/products')
            ->assertStatus(200);

        foreach ($list->json('data') as $row) {
            $this->assertArrayNotHasKey('average_cost', $row);
            $this->assertArrayNotHasKey('commercial_version', $row);
        }

        $this->actingAs($this->cashier)->getJson("/api/v1/products/{$this->buku->id}")
            ->assertStatus(200)
            ->assertJsonMissingPath('data.average_cost');
    }

    public function test_low_stock_filter_includes_at_or_below_minimum(): void
    {
        // AC-37: at minimum -> included; below -> included; above -> excluded.
        $this->buku->forceFill(['stock' => max(1, $this->buku->min_stock)])->save();

        $pulpen = Product::where('sku', 'BRG002')->firstOrFail();
        $pulpen->forceFill(['min_stock' => $pulpen->stock + 10])->save();

        $high = Product::where('sku', 'BRG003')->firstOrFail();
        $high->forceFill(['min_stock' => 0, 'stock' => $high->stock + 10])->save();

        $data = $this->actingAs($this->manager)->getJson('/api/v1/products?low_stock=1')
            ->assertStatus(200)
            ->json('data');

        $skus = array_column($data, 'sku');
        $this->assertContains($this->buku->sku, $skus);
        $this->assertContains($pulpen->sku, $skus);
        $this->assertNotContains($high->sku, $skus);
    }

    public function test_non_price_update_does_not_bump_commercial_version(): void
    {
        $before = $this->buku->commercial_version;

        $this->actingAs($this->manager)->putJson("/api/v1/products/{$this->buku->id}", [
            'description' => 'Deskripsi baru',
        ])->assertStatus(200);

        $this->assertSame($before, (int) $this->buku->fresh()->commercial_version);
    }

    public function test_valid_image_upload_stores_generated_path(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->manager)->postJson('/api/v1/products', [
            'sku' => 'BRG-IMG-1',
            'name' => 'Produk Bergambar',
            'category_id' => $this->category->id,
            'selling_price' => 5000,
            'image' => $this->validPng(),
        ]);

        $response->assertStatus(201);
        $path = Product::where('sku', 'BRG-IMG-1')->value('image_path');
        $this->assertNotNull($path);
        $this->assertStringStartsWith('products/', $path);
        $this->assertStringEndsWith('.png', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_executable_content_disguised_as_png_rejected_without_product_record(): void
    {
        Storage::fake('public');

        $fake = UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>');

        $this->actingAs($this->manager)->postJson('/api/v1/products', [
            'sku' => 'BRG-IMG-2',
            'name' => 'Produk Jahat',
            'category_id' => $this->category->id,
            'selling_price' => 5000,
            'image' => $fake,
        ])->assertStatus(422)->assertJson(['error' => ['code' => 'IMAGE_INVALID_CONTENT']]);

        $this->assertDatabaseMissing('products', ['sku' => 'BRG-IMG-2']);
    }

    public function test_oversized_image_rejected_by_validation(): void
    {
        Storage::fake('public');

        $big = UploadedFile::fake()->create('besar.jpg', 6_000, 'image/jpeg');

        $this->actingAs($this->manager)->postJson('/api/v1/products', [
            'sku' => 'BRG-IMG-3',
            'name' => 'Terlalu Besar',
            'category_id' => $this->category->id,
            'selling_price' => 5000,
            'image' => $big,
        ])->assertStatus(422)->assertJson(['error' => ['code' => 'VALIDATION_ERROR']]);
    }

    // -------------------------------------------------------------------
    // Suppliers
    // -------------------------------------------------------------------

    public function test_manager_can_create_supplier_and_cashier_is_denied(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/suppliers', [
            'code' => 'SUP900',
            'name' => 'Supplier Baru',
        ])->assertStatus(201)->assertJson(['data' => ['code' => 'SUP900']]);

        $this->actingAs($this->cashier)->postJson('/api/v1/suppliers', [
            'code' => 'SUP901',
            'name' => 'Kasir Supplier',
        ])->assertStatus(403)->assertJson(['error' => ['code' => 'CATALOG_FORBIDDEN']]);
    }

    public function test_duplicate_supplier_code_conflict(): void
    {
        $this->actingAs($this->manager)->postJson('/api/v1/suppliers', [
            'code' => 'SUP001',
            'name' => 'Bentrok',
        ])->assertStatus(409)->assertJson(['error' => ['code' => 'SUPPLIER_CODE_TAKEN']]);
    }

    // -------------------------------------------------------------------
    // Customers
    // -------------------------------------------------------------------

    public function test_cashier_can_create_customer_with_generated_code(): void
    {
        $response = $this->actingAs($this->cashier)->postJson('/api/v1/customers', [
            'name' => 'Pelanggan Baru',
            'phone' => '0812345678',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'CUST-004')
            ->assertJsonPath('data.name', 'Pelanggan Baru');
    }

    public function test_cashier_cannot_edit_or_archive_customer(): void
    {
        $customer = Customer::where('code', 'CUST-001')->firstOrFail();

        $this->actingAs($this->cashier)->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Diubah Kasir'])
            ->assertStatus(403)->assertJson(['error' => ['code' => 'CUSTOMER_FORBIDDEN']]);

        $this->actingAs($this->cashier)->postJson("/api/v1/customers/{$customer->id}/archive")
            ->assertStatus(403)->assertJson(['error' => ['code' => 'CUSTOMER_FORBIDDEN']]);
    }

    public function test_manager_can_edit_and_archive_customer(): void
    {
        $customer = Customer::where('code', 'CUST-002')->firstOrFail();

        $this->actingAs($this->manager)->putJson("/api/v1/customers/{$customer->id}", ['name' => 'Nama Diperbarui'])
            ->assertStatus(200)
            ->assertJson(['data' => ['name' => 'Nama Diperbarui']]);

        $this->actingAs($this->manager)->postJson("/api/v1/customers/{$customer->id}/archive")
            ->assertStatus(200)
            ->assertJson(['data' => ['is_active' => false]]);
    }

    public function test_duplicate_customer_code_conflict(): void
    {
        $this->actingAs($this->cashier)->postJson('/api/v1/customers', [
            'code' => 'CUST-001',
            'name' => 'Kode Bentrok',
        ])->assertStatus(409)->assertJson(['error' => ['code' => 'CUSTOMER_CODE_TAKEN']]);
    }
}
