<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->string('stocktake_number', 50)->unique();
            $table->string('status', 20)->default('counting'); // 'counting', 'posted', 'cancelled'
            $table->text('notes')->nullable();
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        Schema::create('stocktake_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stocktake_id')->constrained('stocktakes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('snapshot_stock');
            $table->decimal('snapshot_average_cost', 20, 6);
            $table->integer('counted_stock')->nullable();
            $table->integer('difference')->nullable();
            $table->timestamps();

            $table->unique(['stocktake_id', 'product_id']);
        });

        Schema::create('inventory_control', function (Blueprint $table) {
            $table->id();
            $table->foreignId('active_stocktake_id')->nullable()->constrained('stocktakes')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_control');
        Schema::dropIfExists('stocktake_items');
        Schema::dropIfExists('stocktakes');
    }
};
