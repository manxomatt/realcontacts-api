<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Controller;
use App\Http\Requests\Phone\BatchPhoneLookupRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Phone\BatchLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * BatchLookupController
 *
 * Lookup banyak nomor telepon sekaligus (fitur Premium eksklusif).
 * Menggunakan BatchLookupService untuk performa optimal.
 *
 * Response time target: < 3 detik untuk 10 nomor.
 *
 * Flow:
 *   1. Validasi premium status
 *   2. Panggil BatchLookupService->lookup()
 *   3. Return dengan summary distribusi spam level
 *   4. Format message dinamis via pipe operator PHP 8.5
 *   5. Tambah header X-Batch-Count
 */
#[Middleware('auth:sanctum')]
class BatchLookupController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly BatchLookupService $batchService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Lookup batch — 10 nomor sekaligus (Premium only).
     *
     * POST /api/phone/batch
     *
     * Request body:
     *   {
     *     "phone_numbers": ["+6281xxx", "+6285xxx", ...]
     *   }
     *
     * Response:
     *   {
     *     "summary": {
     *       "total": 10,
     *       "safe": 7,
     *       "warning": 2,
     *       "danger": 1,
     *       "unknown": 0
     *     },
     *     "results": [PhoneNumberResource, ...]
     *   }
     *
     * Header:
     *   X-Batch-Count: 10
     */
    public function lookup(BatchPhoneLookupRequest $request): JsonResponse
    {
        // Validasi: hanya Premium yang dapat menggunakan batch lookup
        if (! $request->user()->is_premium) {
            return ApiResponse::error(
                'Fitur ini khusus pengguna Premium.',
                403,
                ['upgrade_url' => '/api/premium/upgrade'],
            );
        }

        // Panggil service — sudah include formatBatchResponse()
        $response = $this->batchService->lookup(
            $request->validated('phone_numbers') ?? [],
        );

        // Extract summary dan results
        $summary = $response['summary'] ?? [];
        $results = $response['results'] ?? [];
        $total   = (int) ($summary['total'] ?? 0);

        // PHP 8.5 pipe operator |> untuk format message dinamis
        $message = $total
            |> (fn(int $count): string => match (true) {
                $count === 0 => 'Tidak ada nomor yang diproses.',
                $count === 1 => '1 nomor berhasil diproses',
                default      => "{$count} nomor berhasil diproses",
            });

        return ApiResponse::success(
            [
                'summary' => $summary,
                'results' => $results,
            ],
            $message,
        )->withHeaders([
            'X-Batch-Count' => (string) $total,
        ]);
    }
}
