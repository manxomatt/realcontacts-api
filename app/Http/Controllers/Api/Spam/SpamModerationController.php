<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spam;

use App\Exceptions\AlreadyModeratedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Spam\UpdateSpamReportRequest;
use App\Http\Resources\SpamReportCollection;
use App\Http\Resources\SpamReportResource;
use App\Http\Responses\ApiResponse;
use App\Models\SpamReport;
use App\Services\Spam\SpamModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/**
 * SpamModerationController
 *
 * Moderasi laporan spam — ADMIN ONLY.
 * Semua endpoint memerlukan autentikasi Sanctum + role admin.
 *
 * Alur moderasi:
 *   1. pending()  → lihat laporan yang menunggu review
 *   2. approve()  → verifikasi laporan, berikan poin ke reporter
 *   3. reject()   → tolak laporan, optionally hukum reporter
 *   4. stats()    → monitoring aktivitas moderasi
 *
 * Middleware dipasang via routes untuk admin-only protection.
 */
class SpamModerationController extends Controller
{
    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly SpamModerationService $moderationService,
    ) {}

    // ─── Endpoints ────────────────────────────────────────────────────────────

    /**
     * Daftar laporan spam yang menunggu moderasi (paginated).
     * Support filter ?priority=high untuk menampilkan upvotes >= 5 duluan
     * (laporan yang banyak diperkuat community).
     *
     * GET /api/spam/moderation/pending
     */
    public function pending(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $priority = (string) $request->input('priority', '');

        // Query dasar: laporan pending
        $query = SpamReport::where('status', 'pending')
            ->with([
                'phoneNumber:id,phone_number,normalized_number,spam_score',
                'reporter:id,name,badge_level',
            ]);

        // Filter prioritas: laporan dengan upvotes >= 5 dianggap urgent
        if ($priority === 'high') {
            $query->where('upvotes', '>=', 5);
        }

        $reports = $query->orderByDesc('upvotes')
            ->orderBy('created_at')
            ->paginate($perPage);

        return ApiResponse::success(
            new SpamReportCollection($reports),
            'Daftar laporan menunggu moderasi',
        );
    }

    /**
     * Verifikasi dan approve laporan spam.
     * Laporan akan mendapat status='verified' dan reporter mendapat bonus poin.
     *
     * PATCH /api/spam/moderation/{report}/approve
     */
    public function approve(
        UpdateSpamReportRequest $request,
        SpamReport $report,
    ): JsonResponse {
        try {
            // Cek apakah laporan masih pending
            if ($report->status !== 'pending') {
                throw new AlreadyModeratedException(
                    "Laporan ini sudah diproses dengan status '{$report->status}'."
                );
            }

            $approved = $this->moderationService->approve($report, $request->user());

            return ApiResponse::success(
                new SpamReportResource($approved),
                'Laporan berhasil diverifikasi',
            );
        } catch (AlreadyModeratedException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Tolak laporan spam.
     * Laporan akan mendapat status='rejected' dan alasan penolakan dicatat.
     * Reporter mungkin mendapat penalti poin jika rejection rate tinggi.
     *
     * PATCH /api/spam/moderation/{report}/reject
     *
     * Body: {
     *   "rejection_reason": "Bukti tidak cukup jelas"
     * }
     */
    public function reject(
        UpdateSpamReportRequest $request,
        SpamReport $report,
    ): JsonResponse {
        try {
            // Cek apakah laporan masih pending
            if ($report->status !== 'pending') {
                throw new AlreadyModeratedException(
                    "Laporan ini sudah diproses dengan status '{$report->status}'."
                );
            }

            $reason = (string) $request->input('rejection_reason', 'Tidak sesuai kriteria');

            $rejected = $this->moderationService->reject($report, $request->user(), $reason);

            return ApiResponse::success(
                new SpamReportResource($rejected),
                'Laporan berhasil ditolak',
            );
        } catch (AlreadyModeratedException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Statistik moderasi — gambaran aktivitas moderasi hari ini.
     *
     * GET /api/spam/moderation/stats
     *
     * Return:
     *   {
     *     "total_pending": int,
     *     "approved_today": int,
     *     "rejected_today": int,
     *     "top_reporters": [...]
     *   }
     */
    public function stats(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        // Total laporan pending
        $totalPending = (int) SpamReport::where('status', 'pending')->count();

        // Laporan yang diapprove hari ini
        $approvedToday = (int) DB::table('spam_reports')
            ->where('status', 'verified')
            ->whereDate('moderated_at', $today)
            ->count();

        // Laporan yang di-reject hari ini
        $rejectedToday = (int) DB::table('spam_reports')
            ->where('status', 'rejected')
            ->whereDate('moderated_at', $today)
            ->count();

        // Top reporters — mereka yang laporan-nya paling sering diverifikasi
        $topReporters = DB::table('spam_reports')
            ->join('users', 'spam_reports.reporter_user_id', '=', 'users.id')
            ->where('spam_reports.status', 'verified')
            ->select('users.id', 'users.name', 'users.badge_level')
            ->selectRaw('COUNT(*) as verified_count')
            ->whereDate('spam_reports.moderated_at', $today)
            ->groupBy('users.id', 'users.name', 'users.badge_level')
            ->orderByDesc('verified_count')
            ->limit(5)
            ->get()
            ->toArray();

        return ApiResponse::success([
            'total_pending'   => $totalPending,
            'approved_today'  => $approvedToday,
            'rejected_today'  => $rejectedToday,
            'top_reporters'   => $topReporters,
        ], 'Statistik moderasi berhasil diambil');
    }
}
