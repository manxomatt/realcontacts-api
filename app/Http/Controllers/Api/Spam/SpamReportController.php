<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spam;

use App\Exceptions\DuplicateReportException;
use App\Exceptions\NumberWhitelistedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Spam\StoreSpamReportRequest;
use App\Http\Resources\SpamReportCollection;
use App\Http\Resources\SpamReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\SpamReport;
use App\Services\Spam\SpamReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * SpamReportController
 *
 * Mengelola laporan spam dari user — submit, vote, dan riwayat.
 *
 * Semua endpoint memerlukan autentikasi Sanctum (via #[Middleware] di class).
 *
 * Exception mapping:
 *   DuplicateReportException    → 422 (sudah lapor 24 jam terakhir)
 *   NumberWhitelistedException  → 422 (nomor tidak boleh dilaporkan)
 */
#[Middleware('auth:sanctum')]
class SpamReportController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly SpamReportService $reportService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Daftar laporan spam untuk sebuah nomor telepon (paginated).
     * Query param ?status=verified untuk filter hanya yang terverifikasi.
     *
     * GET /api/phone/{phoneNumber}/reports
     */
    public function index(Request $request, string $phoneNumber): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 10);
        $reports = $this->reportService->getByPhoneNumber($phoneNumber, $perPage);

        return ApiResponse::success(new SpamReportCollection($reports), 'Daftar laporan spam');
    }

    /**
     * Kirim laporan spam baru.
     * Mengembalikan laporan + informasi poin kontribusi yang diperoleh.
     *
     * POST /api/spam-reports
     */
    public function store(StoreSpamReportRequest $request): JsonResponse
    {
        try {
            $validated = array_merge(
                $request->validated(),
                ['reporter_user_id' => $request->user()->id],
            );

            $report     = $this->reportService->store($validated);
            $reporter   = $request->user()->fresh();  // Refresh untuk ambil total poin terbaru

            return ApiResponse::success([
                'report'         => new SpamReportResource($report),
                'points_earned'  => 10,
                'total_points'   => (int) $reporter->contribution_points,
                'badge_upgraded' => $reporter->badge_level !== $request->user()->badge_level,
            ], 'Laporan berhasil dikirim, terima kasih kontribusinya!', 201);
        } catch (DuplicateReportException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (NumberWhitelistedException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Vote upvote atau downvote pada sebuah laporan.
     * Validasi inline: type harus 'upvote' atau 'downvote'.
     *
     * POST /api/spam-reports/{report}/vote
     */
    public function vote(Request $request, SpamReport $report): JsonResponse
    {
        $type = (string) $request->input('type', '');

        if (! in_array($type, ['upvote', 'downvote'], strict: true)) {
            return ApiResponse::error("Nilai 'type' harus 'upvote' atau 'downvote'.", 422);
        }

        $counts = $this->reportService->vote($report, $request->user(), $type);

        return ApiResponse::success($counts, 'Vote berhasil dicatat');
    }

    /**
     * Riwayat laporan spam milik user yang sedang login (paginated).
     *
     * GET /api/spam-reports/my
     */
    public function myReports(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 15);
        $reports = $this->reportService->getUserReports($request->user(), $perPage);

        return ApiResponse::success(new SpamReportCollection($reports), 'Riwayat laporan Anda');
    }
}
