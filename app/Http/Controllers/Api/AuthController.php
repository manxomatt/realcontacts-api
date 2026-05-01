<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {}
    /**
     * Registrasi user baru dengan nomor telepon.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return ApiResponse::created(
            [
                'user'     => new UserResource($result['user']),
                'otp_sent' => $result['otp_sent'],
            ],
            'Registrasi berhasil — verifikasi OTP telah dikirim via SMS'
        );
    }

    /**
     * Verifikasi OTP dan terbitkan Sanctum token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->authService->verifyAndLogin($request->validated());

        return ApiResponse::success(
            [
                'user'       => $result['user'],
                'token'      => $result['token'],
                'token_type' => $result['token_type'],
            ],
            'OTP terverifikasi — token akses berhasil diterbitkan'
        );
    }

    /**
     * Logout — cabut Sanctum token aktif.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::noContent('Berhasil logout.');
    }
}
