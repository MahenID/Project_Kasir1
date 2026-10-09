<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('terminal_id')->constrained('terminals')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('starting_cash');
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('actual_cash')->nullable();
            $table->bigInteger('difference')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('explanation')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_status', 30)->nullable();
            $table->json('z_report_snapshot')->nullable();
            $table->timestamps();

            // CASE expression is valid in both MySQL and SQLite (IF() is MySQL-only,
            // which breaks the SQLite in-memory test database).
            $table->unsignedBigInteger('active_user_id')
                ->virtualAs("CASE WHEN status = 'open' THEN user_id ELSE NULL END");
            $table->unsignedBigInteger('active_terminal_id')
                ->virtualAs("CASE WHEN status = 'open' THEN terminal_id ELSE NULL END");

            $table->unique('active_user_id', 'shifts_active_user_unique');
            $table->unique('active_terminal_id', 'shifts_active_terminal_unique');
            $table->index(['status', 'user_id']);
            $table->index(['terminal_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
