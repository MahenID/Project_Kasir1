<?php

declare(strict_types=1);

namespace Tests\Feature;

use Spatie\Activitylog\Models\Activity;
use App\Models\Category;
use App\Models\ExportJob;
use App\Models\InventoryControl;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingItem;
use App\Models\Refund;
use App\Models\ReturnItem;
use App\Models\ReturnRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\StoreSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ReportAndExportApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $manager;
    protected User $cashier;
    protected Terminal $terminal;
    protected Shift $shift;
    protected Product $product;
    protected Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleAndPermissionSeeder::class, StoreSettingSeeder::class]);
        InventoryControl::firstOrCreate(['id' => 1], ['active_stocktake_id' => null]);

        $this->owner = $this->makeUser('owner');
        $this->manager = $this->makeUser('manager');
        $this->cashier = $this->makeUser('cashier');

        $this->terminal = Terminal::create(['code' => 'REP-T1', 'name' => 'Report terminal', 'is_active' => true]);
        $this->shift = Shift::create([
            'user_id' => $this->cashier->id,
            'terminal_id' => $this->terminal->id,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'starting_cash' => 100000,
            'expected_cash' => 150000,
            'actual_cash' => 148000,
            'difference' => -2000,
            'explanation' => 'short 2rb',
            'review_status' => 'pending',
            'status' => 'closed',
        ]);

        $category = Category::create(['name' => 'Report cat', 'slug' => 'report-cat', 'is_active' => true]);
        $this->product = Product::create([
            'sku' => 'REP-SKU-1',
            'name' => 'Produk Laporan',
            'category_id' => $category->id,
            'unit' => 'pcs',
            'selling_price' => 10000,
            'average_cost' => '6000.000000',
            'stock' => 20,
            'minimum_stock' => 5,
            'is_active' => true,
        ]);

        $this->sale = Sale::create([
            'receipt_number' => 'REP-EXACT-001',
            'user_id' => $this->cashier->id,
            'shift_id' => $this->shift->id,
            'terminal_id' => $this->terminal->id,
            'cashier_name_snapshot' => $this->cashier->name,
            'terminal_code_snapshot' => $this->terminal->code,
            'store_snapshot' => [],
            'customer_snapshot' => ['name' => 'Walk-in'],
            'subtotal' => 20000,
            'sale_discount_amount' => 0,
            'net_total' => 20000,
            'tax_amount' => 0,
            'grand_total' => 20000,
            'total_cost' => '12000.000000',
            'status' => 'completed',
            'completed_at' => now()->subMinutes(30),
        ]);
        SaleItem::create([
            'sale_id' => $this->sale->id,
            'product_id' => $this->product->id,
            'sku_snapshot' => $this->product->sku,
            'name_snapshot' => $this->product->name,
            'unit_snapshot' => 'pcs',
            'quantity' => 2,
            'unit_price' => 10000,
            'cost_price_snapshot' => '6000.000000',
            'gross_amount' => 20000,
            'sale_discount_allocation' => 0,
            'net_amount' => 20000,
            'tax_amount' => 0,
            'subtotal' => 20000,
            'extended_cost' => '12000.000000',
        ]);
        Payment::create([
            'sale_id' => $this->sale->id,
            'shift_id' => $this->shift->id,
            'method' => 'cash',
            'amount_due' => 20000,
            'amount_paid' => 20000,
            'change_amount' => 0,
            'confirmed_by' => $this->cashier->id,
            'confirmed_at' => now()->subMinutes(30),
        ]);

        // A line item so returns can reference it.
        $this->sale->load('items');

        // A receiving document with one item.
        $receiving = Receiving::create([
            'receiving_number' => 'RCV-REP-001',
            'type' => 'purchase',
            'status' => 'posted',
            'received_at' => now()->subHours(2),
            'created_by' => $this->owner->id,
            'posted_by' => $this->owner->id,
        ]);
        ReceivingItem::create([
            'receiving_id' => $receiving->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
            'unit_cost' => '6000.000000',
            'extended_cost' => '60000.000000',
        ]);
    }

    protected function makeUser(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        return $user;
    }

    public function test_owner_can_view_sales_summary_with_exact_metrics(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports/sales?start_date=' . now()->subDay()->toDateString() . '&end_date=' . now()->toDateString());
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.net_sales_excluding_tax', 20000);
        $response->assertJsonPath('data.net_cogs', 12000);
        $response->assertJsonPath('data.estimated_gross_profit', 8000);
        $response->assertJsonPath('data.completed_sale_count', 1);
        $response->assertJsonPath('data.average_transaction_value', 20000);
        $response->assertJsonPath('data.payments.total_collected', 20000);
    }

    public function test_cashier_cannot_view_store_wide_reports(): void
    {
        $this->actingAs($this->cashier)
            ->getJson('/api/v1/reports/sales')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_owner_can_view_stock_on_hand_with_valuation(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports/stock');
        $response->assertOk();
        $items = collect($response->json('data.items'));
        $row = $items->firstWhere('sku', 'REP-SKU-1');
        $this->assertNotNull($row);
        $this->assertSame(20, $row['quantity_on_hand']);
        // 20 × 6000 = 120000
        $this->assertSame(120000, $row['inventory_value']);
    }

    public function test_top_products_ranks_by_net_units(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports/top-products?sort=units');
        $response->assertOk();
        $first = $response->json('data.items.0');
        $this->assertSame('REP-SKU-1', $first['sku']);
        $this->assertSame(2, $first['net_product_units']);
        $this->assertSame(20000, $first['net_sales_value']);
    }

    public function test_shift_differences_reports_shortage(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports/shift-differences');
        $response->assertOk();
        $response->assertJsonPath('data.closed_shift_count', 1);
        $response->assertJsonPath('data.short_count', 1);
        $response->assertJsonPath('data.total_difference', -2000);
    }

    public function test_receiving_summary_aggregates_items(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports/receiving');
        $response->assertOk();
        $response->assertJsonPath('data.posted_document_count', 1);
        $response->assertJsonPath('data.total_quantity', 10);
        $response->assertJsonPath('data.total_cost', 60000);
    }

    public function test_excel_export_escapes_formula_injection(): void
    {
        Storage::fake('local');
        // Insert a product with a formula-like name to prove escaping.
        $this->product->update(['name' => '=SUM(A1:A9)']);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/exports', [
            'report_type' => 'stock',
            'format' => 'xlsx',
        ]);
        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'completed');

        $jobId = $response->json('data.id');
        $job = ExportJob::find($jobId);
        $this->assertNotNull($job->file_path);

        $path = Storage::disk('local')->path($job->file_path);
        $this->assertFileExists($path);

        // Unzip and inspect the sheet XML: the value must be prefixed with an apostrophe.
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        // The apostrophe guard is stored in the XML as &apos; (HTML/XML escape of ').
        $this->assertStringContainsString('&apos;=SUM(A1:A9)', $sheet);
    }

    public function test_pdf_export_produces_valid_pdf_header(): void
    {
        Storage::fake('local');
        $response = $this->actingAs($this->owner)->postJson('/api/v1/exports', [
            'report_type' => 'sales',
            'format' => 'pdf',
        ]);
        $response->assertStatus(202);

        $job = ExportJob::find($response->json('data.id'));
        $path = Storage::disk('local')->path($job->file_path);
        $this->assertFileExists($path);
        $this->assertStringStartsWith('%PDF-1.4', file_get_contents($path));
    }

    public function test_expired_export_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $response = $this->actingAs($this->owner)->postJson('/api/v1/exports', [
            'report_type' => 'sales',
            'format' => 'xlsx',
        ]);
        $response->assertStatus(202);

        $job = ExportJob::find($response->json('data.id'));
        $job->update(['expires_at' => now()->subHour()]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/exports/{$job->id}/download")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'EXPORT_EXPIRED');
    }

    public function test_export_download_is_reauthorized_at_download_time(): void
    {
        Storage::fake('local');
        $response = $this->actingAs($this->owner)->postJson('/api/v1/exports', [
            'report_type' => 'sales',
            'format' => 'xlsx',
        ]);
        $job = ExportJob::find($response->json('data.id'));

        // A cashier (no view-all-reports) attempting to download the owner's export.
        $this->actingAs($this->cashier)
            ->getJson("/api/v1/exports/{$job->id}/download")
            ->assertStatus(403);

        // But the owner can download it.
        $download = $this->actingAs($this->owner)->get("/api/v1/exports/{$job->id}/download");
        $download->assertOk();
        $download->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_invalid_export_format_is_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner)
            ->postJson('/api/v1/exports', ['report_type' => 'sales', 'format' => 'csv'])
            ->assertStatus(422);
    }

    public function test_audit_events_are_read_only_and_role_scoped(): void
    {
        $this->seedAuditEntries();

        // Owner sees everything, including sensitive 'settings' log.
        $ownerResponse = $this->actingAs($this->owner)->getJson('/api/v1/audit-events');
        $ownerResponse->assertOk();
        $ownerLogs = collect($ownerResponse->json('data'))->pluck('log_name')->unique()->values()->all();
        $this->assertContains('settings', $ownerLogs);
        $this->assertContains('sales', $ownerLogs);

        // Manager only sees operational logs.
        $managerResponse = $this->actingAs($this->manager)->getJson('/api/v1/audit-events');
        $managerResponse->assertOk();
        $managerLogs = collect($managerResponse->json('data'))->pluck('log_name')->unique()->values()->all();
        $this->assertContains('sales', $managerLogs);
        $this->assertNotContains('settings', $managerLogs);

        // Cashier forbidden.
        $this->actingAs($this->cashier)->getJson('/api/v1/audit-events')->assertStatus(403);
    }

    protected function seedAuditEntries(): void
    {
        Activity::create([
            'log_name' => 'sales',
            'description' => 'sale.completed',
            'event' => 'completed',
            'subject_type' => Sale::class,
            'subject_id' => $this->sale->id,
            'causer_type' => User::class,
            'causer_id' => $this->cashier->id,
            'properties' => ['before' => [], 'after' => ['grand_total' => 20000]],
        ]);
        Activity::create([
            'log_name' => 'settings',
            'description' => 'settings.updated',
            'event' => 'updated',
            'subject_type' => null,
            'subject_id' => null,
            'causer_type' => User::class,
            'causer_id' => $this->owner->id,
            'properties' => ['tax_rate' => 11],
        ]);
    }
}
