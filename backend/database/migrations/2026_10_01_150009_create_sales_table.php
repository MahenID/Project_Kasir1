<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 50)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            
            // Snapshots
            $table->json('customer_snapshot')->nullable();
            $table->string('cashier_name_snapshot', 150);
            $table->string('terminal_code_snapshot', 50);
            $table->json('store_snapshot');

            // Amounts (Integer Rupiah)
            $table->unsignedBigInteger('subtotal')->default(0); // Gross
            $table->unsignedBigInteger('item_discount_total')->default(0);
            $table->string('sale_discount_type', 20)->nullable(); // 'fixed', 'percent'
            $table->unsignedBigInteger('sale_discount_input')->nullable();
            $table->unsignedBigInteger('sale_discount_amount')->default(0);
            $table->unsignedBigInteger('net_total')->default(0); // Taxable subtotal after discounts
            $table->decimal('tax_rate_percent_snapshot', 5, 2)->default(0.00);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);
            $table->decimal('total_cost', 20, 6)->default(0.000000); // Historical COGS snapshot

            $table->string('status', 20)->default('completed'); // 'completed', 'cancelled'
            $table->string('return_status', 20)->default('none'); // 'none', 'partial', 'full'
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['completed_at', 'id']);
            $table->index(['shift_id', 'completed_at']);
            $table->index(['customer_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
