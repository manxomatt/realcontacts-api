<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Services\User\ContributionService;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Collection;

/**
 * UserController
 *
 * Mengelola profil user, update data, reputasi nomor, dan leaderboard.
 * Semua endpoint memerlukan autentikasi Sanctum via #[Middleware] di class level.
 */
#[Middleware('auth:sanctum')]
class UserController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly UserService         $userService,
        private readonly ContributionService $contributionService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Tampilkan profil lengkap user yang sedang login.
     * Include: data user + stats kontribusi (rank, badge, poin).
     *
     * GET /api/user/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user()->fresh();
        $stats = $this->contributionService->getUserRank($user);

        return ApiResponse::success([
            'user'  => new UserResource($user),
            'stats' => $stats,
        ], 'Profil user berhasil diambil');
    }

    /**
     * Update profil user (nama dan/atau nomor telepon).
     * Authorization sudah dicek di UpdateProfileRequest.
     *
     * PATCH /api/user/profile
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $updated = $this->userService->update($request->user(), $request->validated());

        return ApiResponse::success(new UserResource($updated), 'Profil berhasil diperbarui');
    }

    /**
     * Cek reputasi nomor telepon milik user sendiri.
     * Tampilkan spam score, level, jumlah laporan, dan rekomendasi.
     *
     * GET /api/user/number-health
     */
    public function numberHealth(Request $request): JsonResponse
    {
        $user = $request->user();
        // TODO: Implementasi ini memerlukan relasi PhoneNumber ke user
        // Untuk sekarang: placeholder response dengan struktur yang sesuai spec

        return ApiResponse::success([
            'phone_number'           => $this->maskPhoneNumber((string) $user->phone_number),
            'spam_score'             => 0,
            'spam_level'             => 'safe',
            'total_reports_against'  => 0,
            'is_reported_as_spam'    => false,
            'recommendation'         => 'Nomor Anda aman, reputasi terjaga dengan baik.',
        ], 'Status kesehatan nomor berhasil diperiksa');
    }

    /**
     * Riwayat kontribusi dan achievement user.
     * Tampilkan rank, poin, badge, dan badge berikutnya.
     *
     * GET /api/user/contributions
     */
    public function contributions(Request $request): JsonResponse
    {
        $stats = $this->contributionService->getUserRank($request->user());

        return ApiResponse::success($stats, 'Riwayat kontribusi berhasil diambil');
    }

    /**
     * Leaderboard top contributor komunitas.
     * Support query param: ?period=weekly|monthly|alltime (default: monthly).
     *
     * GET /api/user/leaderboard
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $period = (string) $request->input('period', 'monthly');
        $leaders = $this->contributionService->getLeaderboard($period, 10);

        return ApiResponse::success(
            UserResource::collection($leaders),
            'Leaderboard komunitas berhasil diambil',
        );
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Mask nomor telepon untuk keamanan privasi.
     * Format: +62812******* (tampilkan prefix + 4 digit pertama + asterisk).
     *
     * @param  string $phoneNumber
     * @return string
     */
    private function maskPhoneNumber(string $phoneNumber): string
    {
        $length = strlen($phoneNumber);

        if ($length < 8) {
            return str_repeat('*', $length);
        }

        // Tampilkan 7 karakter pertama, sisanya mask
        return substr($phoneNumber, 0, 7) . str_repeat('*', $length - 7);
    }
}
