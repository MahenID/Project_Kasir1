<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 50)->unique();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // 'pending', 'approved', 'rejected', 'completed', 'cancelled'
            $table->text('reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('approval_hash', 64)->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('handling_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->timestamps();

            $table->index(['sale_id', 'status']);
        });

        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained('return_requests')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('disposition', 20)->default('restock'); // 'restock', 'damaged', 'discarded'
            $table->text('reason');
            $table->unsignedBigInteger('refund_net_amount')->nullable();
            $table->unsignedBigInteger('refund_tax_amount')->nullable();
            $table->unsignedBigInteger('refund_total_amount')->nullable();
            $table->decimal('restored_cost_snapshot', 20, 6)->nullable();
            $table->timestamps();

            $table->index(['sale_item_id', 'disposition']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->unique()->constrained('return_requests')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('handling_shift_id')->constrained('shifts')->restrictOnDelete();
            $table->string('method', 20); // 'cash', 'qris', 'edc', 'transfer'
            $table->unsignedBigInteger('amount');
            $table->string('reference_number', 100)->nullable();
            $table->foreignId('processed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['handling_shift_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('return_requests');
    }
};
