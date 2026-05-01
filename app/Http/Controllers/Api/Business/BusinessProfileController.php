<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Business;

use App\Exceptions\AlreadyClaimedException;
use App\Exceptions\UnauthorizedBusinessClaimException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateBusinessProfileRequest;
use App\Http\Resources\BusinessProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\BusinessProfile;
use App\Services\Business\BusinessProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * BusinessProfileController
 *
 * Mengelola profil bisnis — view, klaim kepemilikan, update profil, dan rating.
 * Semua endpoint memerlukan autentikasi Sanctum kecuali mungkin show (review needed).
 *
 * Alur klaim:
 *   1. User melihat nomor bisnis
 *   2. User klaim kepemilikan (claim() method)
 *   3. Sistem set status pending_verification
 *   4. Admin verifikasi claim
 *   5. User bisa update profil setelah verified
 */
#[Middleware('auth:sanctum')]
class BusinessProfileController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly BusinessProfileService $businessService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Tampilkan profil bisnis untuk nomor tertentu.
     * Return 404 jika nomor bukan bisnis atau belum terdaftar.
     *
     * GET /api/business/{phoneNumber}
     */
    public function show(Request $request, string $phoneNumber): JsonResponse
    {
        $profile = BusinessProfile::whereHas(
            'phoneNumber',
            fn($q) => $q->where('normalized_number', $phoneNumber),
        )->first();

        if ($profile === null) {
            return ApiResponse::notFound(
                'Nomor ini bukan nomor bisnis atau belum terdaftar di direktori kami.'
            );
        }

        return ApiResponse::success(
            new BusinessProfileResource($profile),
            'Profil bisnis berhasil ditemukan',
        );
    }

    /**
     * Klaim kepemilikan profil bisnis.
     * Status awal: pending_verification.
     * Admin akan verifikasi dalam 1x24 jam.
     *
     * POST /api/business/{phoneNumber}/claim
     */
    public function claim(Request $request, string $phoneNumber): JsonResponse
    {
        try {
            $profile = $this->businessService->claim($phoneNumber, $request->user());

            return ApiResponse::success([
                'status'  => 'pending_verification',
                'message' => 'Klaim profil sedang diverifikasi, kami akan menghubungi dalam 1x24 jam.',
            ], 'Profil bisnis berhasil diklaim');
        } catch (AlreadyClaimedException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (UnauthorizedBusinessClaimException $e) {
            return ApiResponse::error($e->getMessage(), 403);
        }
    }

    /**
     * Update profil bisnis yang sudah diklaim.
     * Hanya pemilik (claimed_by_user_id) yang dapat update.
     * Authorization sudah dicek di UpdateBusinessProfileRequest.
     *
     * PATCH /api/business/{businessProfile}
     */
    public function update(
        UpdateBusinessProfileRequest $request,
        BusinessProfile $businessProfile,
    ): JsonResponse {
        $updated = $this->businessService->update(
            $businessProfile,
            $request->validated(),
        );

        return ApiResponse::success(
            new BusinessProfileResource($updated),
            'Profil bisnis berhasil diperbarui',
        );
    }

    /**
     * Berikan rating untuk profil bisnis.
     * Rating: 1-5 float (misal: 4.5).
     * Validasi inline: pastikan rating dalam range yang valid.
     *
     * POST /api/business/{phoneNumber}/rate
     */
    public function rate(Request $request, string $phoneNumber): JsonResponse
    {
        $rating = (float) $request->input('rating');

        if ($rating < 1 || $rating > 5) {
            return ApiResponse::error('Rating harus antara 1 hingga 5.', 422);
        }

        try {
            $result = $this->businessService->rate($phoneNumber, $request->user(), $rating);

            return ApiResponse::success($result, 'Rating berhasil dicatat');
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }
    }
}
