<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('report_type', 50);
            $table->json('filters_snapshot');
            $table->string('status', 20)->default('pending'); // 'pending', 'processing', 'completed', 'failed'
            $table->string('file_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('failure_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
