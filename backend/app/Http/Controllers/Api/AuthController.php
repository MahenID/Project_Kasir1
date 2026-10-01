<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Authenticate user using session/cookie auth.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $email = Str::lower(trim($validated['email']));
        $throttleKey = 'login:' . $email . '|' . $request->ip();

        // Failed-login rate limit: max 5 per minute per normalized email + IP
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return ApiResponse::error(
                'RATE_LIMIT_EXCEEDED',
                "Terlalu banyak percobaan login yang gagal. Silakan coba kembali dalam {$seconds} detik.",
                ['retry_after' => $seconds],
                429
            );
        }

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'Email atau kata sandi yang Anda masukkan salah.',
                [],
                401
            );
        }

        if (!$user->is_active) {
            RateLimiter::hit($throttleKey, 60);
            return ApiResponse::error(
                'ACCOUNT_INACTIVE',
                'Akun Anda telah dinonaktifkan. Silakan hubungi pemilik atau manajer toko.',
                [],
                403
            );
        }

        // Clear rate limiter on success
        RateLimiter::clear($throttleKey);

        // Perform session login and regenerate session ID
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return ApiResponse::success(
            $this->formatUserPayload($user),
            'Login berhasil.'
        );
    }

    /**
     * Invalidate session and logout.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, 'Berhasil keluar dari sistem.');
    }

    /**
     * Return currently authenticated user profile, roles, permissions, and active shift.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return ApiResponse::error('UNAUTHENTICATED', 'Belum terotentikasi.', [], 401);
        }

        return ApiResponse::success($this->formatUserPayload($user));
    }

    /**
     * Update own user profile (name, phone).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:25',
        ]);

        $user->update([
            'name' => $validated['name'],
            'phone' => $validated['phone'] ?? $user->phone,
        ]);

        return ApiResponse::success($this->formatUserPayload($user), 'Profil berhasil diperbarui.');
    }

    /**
     * Update own password requiring current password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|current_password:web',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return ApiResponse::success(null, 'Kata sandi berhasil diperbarui.');
    }

    /**
     * Format user payload safely, excluding cost/margin data for cashiers.
     */
    private function formatUserPayload(User $user): array
    {
        $roles = $user->getRoleNames()->values()->all();
        $permissions = $user->getAllPermissions()->pluck('name')->values()->all();
        $openShift = $user->currentOpenShift;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'must_change_password' => $user->must_change_password,
            'roles' => $roles,
            'permissions' => $permissions,
            'current_shift' => $openShift ? [
                'id' => $openShift->id,
                'shift_number' => $openShift->shift_number,
                'terminal_id' => $openShift->terminal_id,
                'terminal_code' => $openShift->terminal?->code,
                'opened_at' => $openShift->opened_at?->toIso8601String(),
                'starting_cash' => $openShift->starting_cash,
            ] : null,
        ];
    }
}
