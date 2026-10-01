<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity'); // Signed delta (+ receiving, - sale, etc.)
            $table->string('type', 40); // 'sale', 'receiving_purchase', 'receiving_opening', 'adjustment_quantity', 'adjustment_revaluation', 'stocktake_adjustment', 'return_restock'
            $table->string('reference_type', 100);
            $table->unsignedBigInteger('reference_id');
            $table->unsignedBigInteger('reference_line_id')->nullable();
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->decimal('cost_before', 20, 6);
            $table->decimal('cost_after', 20, 6);
            $table->decimal('total_value_before', 20, 6);
            $table->decimal('total_value_after', 20, 6);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->index(['product_id', 'posted_at', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
