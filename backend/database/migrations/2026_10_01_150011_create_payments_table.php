<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained('sales')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->restrictOnDelete();
            $table->string('method', 20); // 'cash', 'qris', 'edc', 'transfer'
            $table->unsignedBigInteger('amount_due');
            $table->unsignedBigInteger('amount_paid');
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->string('reference_number', 100)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('refund_status', 20)->default('none'); // 'none', 'partial', 'full'
            $table->timestamps();

            $table->index(['shift_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
