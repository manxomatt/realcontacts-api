<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    /**
     * Registrasi user baru dengan nomor telepon.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::success(
            $request->validated(),
            'Registrasi berhasil — implementasi Service pending'
        );
    }

    /**
     * Verifikasi OTP dan terbitkan Sanctum token.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        // TODO: implementasi service
        return ApiResponse::success(
            $request->validated(),
            'OTP valid — implementasi Service pending'
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
