<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('return_items')->where('disposition', 'restock')->update(['disposition' => 'restockable']);
        DB::table('return_items')->where('disposition', 'discarded')->update(['disposition' => 'damaged']);
        DB::table('return_requests')->where('status', 'approved')->update([
            'status' => 'requested',
            'approved_by' => null,
            'approved_at' => null,
            'approval_hash' => null,
            'approval_expires_at' => null,
        ]);
        Schema::table('return_items', function (Blueprint $table) {
            $table->string('disposition', 20)->default('restockable')->change();
        });
    }

    public function down(): void
    {
        DB::table('return_items')->where('disposition', 'restockable')->update(['disposition' => 'restock']);
        Schema::table('return_items', function (Blueprint $table) {
            $table->string('disposition', 20)->default('restock')->change();
        });
    }
};
