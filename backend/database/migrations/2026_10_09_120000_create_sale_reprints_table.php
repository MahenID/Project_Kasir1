<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail for receipt reprints.
     *
     * Reprints never modify the parent sale: a printer failure must not
     * duplicate or void the transaction. Every reprint call inserts a
     * row here and emits an activity_log entry, so the cashier can
     * reproduce the original receipt on demand while the audit trail
     * remains the single source of truth for "who reprinted what, when".
     */
    public function up(): void
    {
        Schema::create('sale_reprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('reprinted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 100)->nullable();
            $table->string('source_ip', 45)->nullable();
            $table->timestamp('reprinted_at');
            $table->timestamps();

            $table->index(['sale_id', 'reprinted_at']);
            $table->index('reprinted_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_reprints');
    }
};
