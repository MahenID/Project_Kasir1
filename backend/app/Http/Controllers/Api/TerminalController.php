<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Terminal;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    /**
     * GET /api/v1/terminals
     *
     * Daftar terminal kasir terdaftar. Semua peran terautentikasi (owner,
     * manager, cashier) dapat mengakses ini untuk keperluan pemilihan
     * terminal saat membuka shift (PRD v2 §4.3).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Terminal::query()->orderBy('code');

        if ($request->has('active_only')) {
            $activeOnly = filter_var($request->query('active_only'), FILTER_VALIDATE_BOOLEAN);
            if ($activeOnly) {
                $query->where('is_active', true);
            }
            // active_only=false berarti tampilkan semua terminal (aktif + non-aktif).
        } else {
            // Default hanya tampilkan terminal aktif untuk operasional kasir buka shift.
            $query->where('is_active', true);
        }
        $terminals = $query->get()->map(fn (Terminal $t) => [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'is_active' => (bool) $t->is_active,
        ])->all();

        return ApiResponse::success($terminals, 'Daftar terminal.');
    }
}
