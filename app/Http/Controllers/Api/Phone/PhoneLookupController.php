<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Phone;

use App\Exceptions\InvalidPhoneNumberException;
use App\Exceptions\RateLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Phone\PhoneLookupRequest;
use App\Http\Resources\PhoneNumberCollection;
use App\Http\Resources\PhoneNumberLookupResource;
use App\Http\Responses\ApiResponse;
use App\Services\Cache\PhoneCacheService;
use App\Services\Phone\PhoneLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * PhoneLookupController
 *
 * Controller dengan traffic tertinggi di RealContacts.
 * Semua endpoint memerlukan Sanctum token (auth:sanctum).
 *
 * Performa: PhoneCacheService di-peek sebelum lookup untuk
 * mengisi header X-Cache: HIT/MISS tanpa double-processing.
 * Redis GET sangat cepat (<1ms) sehingga overhead minimal.
 *
 * Exception mapping:
 *   InvalidPhoneNumberException  → 422
 *   RateLimitExceededException   → 429 + detail upgrade Premium
 */
#[Middleware('auth:sanctum')]
class PhoneLookupController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly PhoneLookupService $lookupService,
        private readonly PhoneCacheService  $cacheService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Lookup satu nomor telepon secara lengkap.
     *
     * GET /api/phone/{number}
     *
     * Header response:
     *   X-Cache      : HIT jika data dari cache Redis, MISS jika dari DB
     *   X-Spam-Level : safe | warning | danger
     */
    public function show(PhoneLookupRequest $request, string $number): JsonResponse
    {
        try {
            // Peek cache sebelum lookup — tentukan HIT/MISS tanpa overhead berarti
            $normalized = $request->normalizedNumber();
            $cacheHit   = $this->cacheService->get($normalized) !== null;

            $data = $this->lookupService->lookup($number);

            return ApiResponse::success(new PhoneNumberLookupResource($data), 'Data nomor berhasil ditemukan')
                ->withHeaders([
                    'X-Cache'      => $cacheHit ? 'HIT' : 'MISS',
                    'X-Spam-Level' => (string) ($data['spam_level'] ?? 'unknown'),
                ]);
        } catch (InvalidPhoneNumberException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (RateLimitExceededException $e) {
            return $this->rateLimitResponse($e);
        }
    }

    /**
     * Full-text search nomor atau nama pemilik.
     * Minimum 3 karakter — divalidasi inline.
     *
     * GET /api/phone/search?query={kata_kunci}
     */
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('query', ''));

        if (mb_strlen($query) < 3) {
            return ApiResponse::error('Kata kunci pencarian minimal 3 karakter.', 422);
        }

        $results = $this->lookupService->search($query);

        return ApiResponse::success(new PhoneNumberCollection($results), 'Hasil pencarian');
    }

    /**
     * Riwayat 10 nomor yang terakhir dicari oleh user ini.
     *
     * GET /api/phone/recent
     */
    public function recentLookups(Request $request): JsonResponse
    {
        $results = $this->lookupService->getRecentlyLookedUp(
            userId: (int) $request->user()->id,
            limit: 10,
        );

        return ApiResponse::success(new PhoneNumberCollection($results), 'Riwayat pencarian terakhir');
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Bangun response 429 dengan detail limit dan pesan upgrade Premium.
     * Header Retry-After sesuai RFC 7231.
     */
    private function rateLimitResponse(RateLimitExceededException $e): JsonResponse
    {
        return ApiResponse::error($e->getMessage(), 429, [
            'limit_type'       => $e->getLimitType(),
            'upgrade_message'  => 'Upgrade ke Premium untuk unlimited lookup',
            'retry_after'      => $e->getRetryAfter(),
        ])->withHeaders([
            'Retry-After' => (string) $e->getRetryAfter(),
        ]);
    }
}
