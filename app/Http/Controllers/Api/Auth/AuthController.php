<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\OtpBlacklistedException;
use App\Exceptions\OtpExpiredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * AuthController
 *
 * Endpoint publik (tanpa middleware) kecuali logout dan me
 * yang memerlukan Sanctum token aktif.
 *
 * Exception handling terpusat di setiap method agar
 * response HTTP sesuai dengan jenis kesalahan OTP.
 */
class AuthController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly AuthService $authService,
    ) {}

    // ─── Public Endpoints ─────────────────────────────────────────────────────

    /**
     * Registrasi user baru dengan nomor telepon.
     * OTP dikirim otomatis setelah registrasi berhasil.
     *
     * POST /api/auth/register
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->register($request->validated());

            return ApiResponse::success(
                new UserResource($result['user']),
                'Registrasi berhasil, kode OTP telah dikirim',
                201,
            );
        } catch (OtpBlacklistedException $e) {
            return $this->blacklistedResponse($e);
        }
    }

    /**
     * Verifikasi kode OTP dan terbitkan Sanctum token.
     *
     * POST /api/auth/verify
     */
    public function verifyAndLogin(VerifyOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->verifyAndLogin($request->validated());

            return ApiResponse::success([
                'user'       => new UserResource($result['user']),
                'token'      => $result['token'],
                'token_type' => 'Bearer',
            ], 'Login berhasil');
        } catch (OtpExpiredException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (OtpBlacklistedException $e) {
            return $this->blacklistedResponse($e);
        } catch (AuthenticationException) {
            return ApiResponse::error('Nomor telepon atau kode OTP tidak valid.', 401);
        }
    }

    /**
     * Logout — cabut Sanctum token yang sedang aktif.
     *
     * POST /api/auth/logout
     */
    #[Middleware('auth:sanctum')]
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(null, 'Logout berhasil');
    }

    /**
     * Kembalikan data user yang sedang login (profil diri sendiri).
     *
     * GET /api/auth/me
     */
    #[Middleware('auth:sanctum')]
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()), 'Data profil berhasil diambil');
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Bangun response 429 Too Many Requests untuk OTP yang diblokir.
     * Menyertakan retry_after (detik) agar klien tahu kapan boleh coba lagi.
     */
    private function blacklistedResponse(OtpBlacklistedException $e): JsonResponse
    {
        return ApiResponse::error(
            'Terlalu banyak percobaan OTP. Coba lagi setelah beberapa saat.',
            429,
            ['retry_after' => $e->getRetryAfter()],
        );
    }
}
