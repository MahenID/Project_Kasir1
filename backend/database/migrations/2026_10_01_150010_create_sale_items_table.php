<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            
            // Product Snapshots
            $table->string('sku_snapshot', 64);
            $table->string('barcode_snapshot', 64)->nullable();
            $table->string('name_snapshot', 200);
            $table->string('unit_snapshot', 30);
            
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->decimal('cost_price_snapshot', 20, 6);
            
            $table->unsignedBigInteger('gross_amount');
            $table->string('discount_type', 20)->nullable(); // 'fixed', 'percent'
            $table->unsignedBigInteger('discount_input')->nullable();
            $table->unsignedBigInteger('item_discount_amount')->default(0);
            $table->unsignedBigInteger('sale_discount_allocation')->default(0);
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('subtotal');
            $table->decimal('extended_cost', 20, 6);
            $table->timestamps();

            $table->unique(['sale_id', 'product_id']);
            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
