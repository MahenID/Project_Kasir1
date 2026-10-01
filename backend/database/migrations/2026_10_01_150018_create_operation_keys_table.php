<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 50); // 'checkout', 'refund', 'receiving', 'adjustment', 'stocktake', 'cash_movement', 'shift'
            $table->string('key', 64);
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('pending'); // 'pending', 'succeeded', 'failed'
            $table->string('result_resource_type', 100)->nullable();
            $table->unsignedBigInteger('result_resource_id')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'operation', 'key'], 'operation_keys_user_op_key_unique');
            $table->index(['operation', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_keys');
    }
};
