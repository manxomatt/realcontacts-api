<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Block;

use App\Exceptions\BlocklistLimitException;
use App\Exceptions\SelfBlockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Block\StoreBlockedNumberRequest;
use App\Http\Resources\BlockedNumberCollection;
use App\Http\Resources\BlockedNumberResource;
use App\Http\Responses\ApiResponse;
use App\Services\Block\CallBlockerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * CallBlockerController
 *
 * Mengelola daftar blokir panggilan pengguna.
 * Semua endpoint memerlukan autentikasi Sanctum via #[Middleware] di class level.
 *
 * Alur umum:
 *   - index/store/destroy/check mengakses service
 *   - Service menggunakan Redis Set blocklist:{user_id} untuk performa O(1) cek
 *   - Database sebagai source of truth untuk persistence
 *
 * Exception mapping:
 *   SelfBlockException        → 422 (user blokir nomor sendiri)
 *   BlocklistLimitException   → 422 (sudah 500 nomor, perlu upgrade)
 */
#[Middleware('auth:sanctum')]
class CallBlockerController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly CallBlockerService $blockerService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Daftar semua nomor yang diblokir user ini (paginated).
     * Support filter: ?type=manual|category|schedule
     *
     * GET /api/block/blocklist
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $blocklist = $this->blockerService->getUserBlocklist($request->user(), $perPage);

        return ApiResponse::success(new BlockedNumberCollection($blocklist), 'Daftar blokir Anda');
    }

    /**
     * Tambahkan nomor ke daftar blokir.
     *
     * POST /api/block/blocklist
     */
    public function store(StoreBlockedNumberRequest $request): JsonResponse
    {
        try {
            $blocked = $this->blockerService->block($request->user(), $request->validated());

            return ApiResponse::success(
                new BlockedNumberResource($blocked),
                'Nomor berhasil diblokir',
                201,
            );
        } catch (SelfBlockException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (BlocklistLimitException $e) {
            return ApiResponse::error(
                $e->getMessage() . ' Upgrade ke Premium untuk unlimited blocklist.',
                422,
                ['limit_reached' => $e->getLimitReached()],
            );
        }
    }

    /**
     * Hapus nomor dari daftar blokir.
     *
     * DELETE /api/block/blocklist/{phoneNumber}
     */
    public function destroy(Request $request, string $phoneNumber): JsonResponse
    {
        $success = $this->blockerService->unblock($request->user(), $phoneNumber);

        if (! $success) {
            return ApiResponse::error('Nomor tidak ditemukan di daftar blokir Anda.', 404);
        }

        return ApiResponse::success(null, 'Nomor berhasil dihapus dari daftar blokir');
    }

    /**
     * Cek apakah sebuah nomor diblokir oleh user ini.
     * Dipakai aplikasi Flutter saat ada panggilan masuk.
     *
     * GET /api/block/check/{phoneNumber}
     */
    public function check(Request $request, string $phoneNumber): JsonResponse
    {
        $isBlocked = $this->blockerService->isBlocked($request->user(), $phoneNumber);

        return ApiResponse::success([
            'is_blocked'   => $isBlocked,
            'phone_number' => $phoneNumber,
        ], 'Status blokir diperiksa');
    }

    /**
     * Import bulk daftar blokir dari array nomor (CSV/file upload).
     * Maksimal 500 nomor per import.
     * PHP 8.5 pipe operator |> untuk format pesan dinamis.
     *
     * POST /api/block/import
     *
     * Body: {
     *   "phone_numbers": ["+6281xxx", "+6285xxx", ...]
     * }
     */
    public function import(Request $request): JsonResponse
    {
        $phoneNumbers = (array) $request->input('phone_numbers', []);

        if (empty($phoneNumbers)) {
            return ApiResponse::error('Minimal ada 1 nomor yang harus diimpor.', 422);
        }

        if (count($phoneNumbers) > 500) {
            return ApiResponse::error('Maksimal 500 nomor per import.', 422);
        }

        $result = $this->blockerService->importBlocklist($request->user(), $phoneNumbers);

        // PHP 8.5 pipe operator |> untuk membangun pesan dinamis
        $message = $result['imported']
            |> (fn(int $count): string => match (true) {
                $count === 0 => 'Tidak ada nomor yang berhasil diimpor.',
                $count === count($phoneNumbers) => 'Semua nomor berhasil diimpor!',
                default => "{$count} dari " . count($phoneNumbers) . ' nomor berhasil diimpor.',
            });

        return ApiResponse::success($result, $message);
    }
}
