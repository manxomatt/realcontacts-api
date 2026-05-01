<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\OtpBlacklistedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * OtpController
 *
 * Mengelola pengiriman dan pengiriman ulang kode OTP.
 *
 * Rate limit via #[Middleware]: 3 request per menit per IP.
 *
 * Response headers tambahan:
 *   X-OTP-Expires-In         : 300 (5 menit dalam detik)
 *   X-OTP-Remaining-Attempts : sisa attempt sebelum blacklist
 */
#[Middleware('throttle:3,1')]
class OtpController extends Controller
{
    /** Masa berlaku OTP dalam detik */
    private const OTP_TTL = 300;

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly AuthService $authService,
        private readonly OtpService  $otpService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Kirim OTP ke nomor telepon.
     * Dipakai langsung setelah registrasi atau untuk verifikasi pertama.
     *
     * POST /api/otp/request
     */
    public function request(RequestOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->requestNewOtp(
                (string) $request->validated('phone_number')
            );

            return $this->otpResponse(
                phoneNumber: (string) $request->validated('phone_number'),
                retryAfter: (int) $result['retry_after'],
                message: 'Kode OTP telah dikirim',
            );
        } catch (OtpBlacklistedException $e) {
            return $this->blacklistedResponse($e);
        }
    }

    /**
     * Kirim ulang OTP untuk user yang sudah terdaftar.
     * ResendOtpRequest memvalidasi bahwa nomor sudah ada di tabel users.
     *
     * POST /api/otp/resend
     */
    public function resend(ResendOtpRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->requestNewOtp(
                (string) $request->validated('phone_number')
            );

            return $this->otpResponse(
                phoneNumber: (string) $request->validated('phone_number'),
                retryAfter: (int) $result['retry_after'],
                message: 'Kode OTP baru telah dikirim',
            );
        } catch (OtpBlacklistedException $e) {
            return $this->blacklistedResponse($e);
        }
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Bangun JsonResponse sukses dengan header OTP standar.
     *
     * Header X-OTP-Remaining-Attempts memberi tahu klien berapa
     * percobaan tersisa sebelum nomor masuk blacklist.
     */
    private function otpResponse(string $phoneNumber, int $retryAfter, string $message): JsonResponse
    {
        $remaining = $this->otpService->getRemainingAttempts($phoneNumber);

        return ApiResponse::success(
            data: [
                'retry_after' => $retryAfter,
                'expires_in'  => self::OTP_TTL,
            ],
            message: $message,
        )->withHeaders([
            'X-OTP-Expires-In'          => (string) self::OTP_TTL,
            'X-OTP-Remaining-Attempts'  => (string) $remaining,
        ]);
    }

    /**
     * Bangun response 429 Too Many Requests untuk OTP yang diblokir.
     * Header Retry-After mengikuti RFC 7231 agar klien HTTP bisa handle otomatis.
     */
    private function blacklistedResponse(OtpBlacklistedException $e): JsonResponse
    {
        return ApiResponse::error(
            'Terlalu banyak percobaan OTP. Coba lagi setelah beberapa saat.',
            429,
            ['retry_after' => $e->getRetryAfter()],
        )->withHeaders([
            'Retry-After' => (string) $e->getRetryAfter(),
        ]);
    }
}
