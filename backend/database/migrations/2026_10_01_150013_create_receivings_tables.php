<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivings', function (Blueprint $table) {
            $table->id();
            $table->string('receiving_number', 50)->unique();
            $table->string('type', 20)->default('purchase'); // 'purchase', 'opening'
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('external_reference', 100)->nullable();
            $table->string('status', 20)->default('draft'); // 'draft', 'posted', 'cancelled'
            $table->timestamp('received_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('correction_notes')->nullable();
            $table->foreignId('linked_receiving_id')->nullable()->constrained('receivings')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['received_at', 'id']);
        });

        Schema::create('receiving_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receiving_id')->constrained('receivings')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('extended_cost', 20, 6);
            $table->json('product_snapshot')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_items');
        Schema::dropIfExists('receivings');
    }
};
