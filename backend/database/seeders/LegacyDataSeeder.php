<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receiving;
use App\Models\ReceivingItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LegacyDataSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('email', 'owner@dragonmart.local')->first()
            ?? User::first();

        DB::transaction(function () use ($owner) {
            $now = Carbon::now('UTC');

            // 1. Seed Suppliers from kasirdragon.sql
            $suppliersData = [
                [
                    'code' => 'SUP001',
                    'name' => 'PT Sinar Dunia',
                    'address' => 'Jl. Mawar No.1, Jakarta',
                    'phone' => '081234567890',
                    'email' => 'sinardunia@gmail.com',
                ],
                [
                    'code' => 'SUP002',
                    'name' => 'CV Gramedia',
                    'address' => 'Jl. Melati No.5, Bandung',
                    'phone' => '082234567891',
                    'email' => 'Gramedia@yahoo.com',
                ],
                [
                    'code' => 'SUP003',
                    'name' => 'Toko Alat Tulis Surya',
                    'address' => 'Jl. Kenanga No.3, Surabaya',
                    'phone' => '083334567892',
                    'email' => 'suryamerdeka@yahoo.co.id',
                ],
                [
                    'code' => 'SUP004',
                    'name' => 'PT Pulpen Warna',
                    'address' => 'Jl. Anggrek No.2, Yogyakarta',
                    'phone' => '084434567893',
                    'email' => 'Pulpenwarna@gmail.com',
                ],
                [
                    'code' => 'SUP006',
                    'name' => 'PT Deterjen wangy',
                    'address' => 'Jln.cokro Aminoto No 16',
                    'phone' => '082142103834',
                    'email' => 'Wangywangy@gmail.com',
                ],
            ];

            $supplierMap = [];
            foreach ($suppliersData as $sData) {
                $supplier = Supplier::updateOrCreate(
                    ['code' => $sData['code']],
                    [
                        'name' => $sData['name'],
                        'address' => $sData['address'],
                        'phone' => $sData['phone'],
                        'email' => $sData['email'],
                        'is_active' => true,
                    ]
                );
                $supplierMap[$sData['code']] = $supplier->id;
            }

            // 2. Seed Categories from kasirdragon.sql
            $categoriesData = [
                ['slug' => 'buku-tulis', 'name' => 'Buku Tulis'],
                ['slug' => 'pulpen', 'name' => 'Pulpen'],
                ['slug' => 'pensil', 'name' => 'Pensil'],
                ['slug' => 'penghapus', 'name' => 'Penghapus'],
                ['slug' => 'sabun', 'name' => 'Sabun'],
                ['slug' => 'deterjen', 'name' => 'Deterjen'],
            ];

            $categoryMap = [];
            foreach ($categoriesData as $cData) {
                $category = Category::updateOrCreate(
                    ['slug' => $cData['slug']],
                    [
                        'name' => $cData['name'],
                        'is_active' => true,
                    ]
                );
                $categoryMap[$cData['slug']] = $category->id;
            }

            // 3. Seed Customers from kasirdragon.sql
            $customersData = [
                [
                    'code' => 'CUST-001',
                    'name' => 'dewa july',
                    'phone' => '081330362741',
                    'email' => 'dewashat@gmail.com',
                    'address' => 'Jln.suhartono mulyono No 163 Denpasar Barat',
                ],
                [
                    'code' => 'CUST-002',
                    'name' => 'Siti Nurhaliza',
                    'phone' => '081298765432',
                    'email' => 'siti@email.com',
                    'address' => 'Jl. Pahlawan No. 456, Bandung',
                ],
                [
                    'code' => 'CUST-003',
                    'name' => 'Juan Beti',
                    'phone' => '081330362741',
                    'email' => null,
                    'address' => 'Jln.cokro Aminoto No 16',
                ],
            ];

            foreach ($customersData as $custData) {
                Customer::updateOrCreate(
                    ['code' => $custData['code']],
                    [
                        'name' => $custData['name'],
                        'phone' => $custData['phone'],
                        'email' => $custData['email'],
                        'address' => $custData['address'],
                        'is_active' => true,
                    ]
                );
            }

            // 4. Create Opening Receiving document for inventory ledger integrity
            $openingReceiving = Receiving::updateOrCreate(
                ['receiving_number' => 'RCV-OPENING-001'],
                [
                    'type' => 'opening',
                    'supplier_id' => null,
                    'external_reference' => 'MIGRASI-KASIRDRAGON',
                    'status' => 'posted',
                    'received_at' => $now,
                    'created_by' => $owner->id,
                    'posted_by' => $owner->id,
                    'correction_notes' => 'Saldo awal migrasi data dari sistem kasirdragon legacy',
                ]
            );

            // 5. Seed Products and Opening Balances
            $productsData = [
                [
                    'sku' => 'BRG001',
                    'barcode' => '899000100001',
                    'name' => 'Buku Tulis A5',
                    'description' => 'Buku tulis ukuran A5 isi 38 lembar',
                    'category_slug' => 'buku-tulis',
                    'supplier_code' => 'SUP001',
                    'unit' => 'PCS',
                    'selling_price' => 5000,
                    'average_cost' => '3500.000000',
                    'stock' => 75,
                    'min_stock' => 10,
                ],
                [
                    'sku' => 'BRG002',
                    'barcode' => '899000100002',
                    'name' => 'Pulpen Biru',
                    'description' => 'Ballpoint warna biru 0.5mm',
                    'category_slug' => 'pulpen',
                    'supplier_code' => 'SUP004',
                    'unit' => 'PCS',
                    'selling_price' => 2500,
                    'average_cost' => '1750.000000',
                    'stock' => 183,
                    'min_stock' => 20,
                ],
                [
                    'sku' => 'BRG003',
                    'barcode' => '899000100003',
                    'name' => 'Pensil HB',
                    'description' => 'Pensil grafit standar HB',
                    'category_slug' => 'pensil',
                    'supplier_code' => 'SUP003',
                    'unit' => 'PCS',
                    'selling_price' => 1500,
                    'average_cost' => '1000.000000',
                    'stock' => 124,
                    'min_stock' => 20,
                ],
                [
                    'sku' => 'BRG004',
                    'barcode' => '899000100004',
                    'name' => 'Penghapus Kecil',
                    'description' => 'Penghapus pensil karet ukuran mini',
                    'category_slug' => 'penghapus',
                    'supplier_code' => 'SUP002',
                    'unit' => 'PCS',
                    'selling_price' => 1000,
                    'average_cost' => '700.000000',
                    'stock' => 78,
                    'min_stock' => 15,
                ],
                [
                    'sku' => 'BRG005',
                    'barcode' => '899000100005',
                    'name' => 'Sabun Shinzui',
                    'description' => 'Sabun mandi batang Shinzui aroma herbal matsuri',
                    'category_slug' => 'sabun',
                    'supplier_code' => 'SUP006',
                    'unit' => 'PCS',
                    'selling_price' => 38000,
                    'average_cost' => '28000.000000',
                    'stock' => 87,
                    'min_stock' => 10,
                ],
                [
                    'sku' => 'BRG007',
                    'barcode' => '899000100007',
                    'name' => 'Deterjen Rinso',
                    'description' => 'Deterjen bubuk anti noda Rinso 800g',
                    'category_slug' => 'deterjen',
                    'supplier_code' => 'SUP006',
                    'unit' => 'PCS',
                    'selling_price' => 26000,
                    'average_cost' => '20000.000000',
                    'stock' => 122,
                    'min_stock' => 15,
                ],
            ];

            foreach ($productsData as $pData) {
                $categorySlug = $pData['category_slug'];
                $supplierCode = $pData['supplier_code'];

                $product = Product::updateOrCreate(
                    ['sku' => $pData['sku']],
                    [
                        'barcode' => $pData['barcode'],
                        'name' => $pData['name'],
                        'description' => $pData['description'],
                        'category_id' => $categoryMap[$categorySlug] ?? null,
                        'preferred_supplier_id' => $supplierMap[$supplierCode] ?? null,
                        'unit' => $pData['unit'],
                        'selling_price' => $pData['selling_price'],
                        'average_cost' => $pData['average_cost'],
                        'stock' => $pData['stock'],
                        'min_stock' => $pData['min_stock'],
                        'is_active' => true,
                        'commercial_version' => 1,
                    ]
                );

                $extendedCost = bcmul((string) $pData['stock'], (string) $pData['average_cost'], 6);

                // Add or update receiving item line
                $receivingItem = ReceivingItem::updateOrCreate(
                    [
                        'receiving_id' => $openingReceiving->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'quantity' => $pData['stock'],
                        'unit_cost' => $pData['average_cost'],
                        'extended_cost' => $extendedCost,
                        'product_snapshot' => [
                            'sku' => $product->sku,
                            'name' => $product->name,
                            'barcode' => $product->barcode,
                            'unit' => $product->unit,
                        ],
                    ]
                );

                // Ledger record in stock_movements
                StockMovement::updateOrCreate(
                    [
                        'reference_type' => 'receiving',
                        'reference_id' => $openingReceiving->id,
                        'reference_line_id' => $receivingItem->id,
                    ],
                    [
                        'product_id' => $product->id,
                        'quantity' => $pData['stock'],
                        'type' => 'receiving_opening',
                        'stock_before' => 0,
                        'stock_after' => $pData['stock'],
                        'cost_before' => '0.000000',
                        'cost_after' => $pData['average_cost'],
                        'total_value_before' => '0.000000',
                        'total_value_after' => $extendedCost,
                        'user_id' => $owner->id,
                        'reason' => 'Saldo awal migrasi data kasirdragon',
                        'posted_at' => $now,
                    ]
                );
            }
        });
    }
}
