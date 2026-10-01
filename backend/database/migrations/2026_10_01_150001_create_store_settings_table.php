<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->default('DragonMart POS');
            $table->text('address')->nullable();
            $table->string('phone', 25)->nullable();
            $table->string('email', 100)->nullable();
            $table->text('receipt_footer')->nullable();
            $table->string('timezone', 50)->default('Asia/Makassar');
            $table->boolean('tax_enabled')->default(false);
            $table->decimal('tax_rate_percent', 5, 2)->default(0.00);
            $table->decimal('max_cashier_discount_percent', 5, 2)->default(0.00);
            $table->unsignedInteger('return_window_days')->default(7);
            $table->unsignedInteger('settings_version')->default(1);
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
