<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PhoneNumberCollection;
use App\Http\Responses\ApiResponse;
use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DashboardController
 *
 * Admin dashboard untuk monitoring statistik, aktivitas, dan kualitas data aplikasi.
 * Semua endpoint dilindungi middleware admin-only.
 *
 * Caching strategy:
 *   - Stats & leaderboard: 5 menit (Redis)
 *   - Activity: 1 menit (lebih fresh karena real-time)
 *
 * Middleware dipasang via routes untuk admin-only protection.
 */
class DashboardController extends Controller
{
    // ─── Cache TTL ─────────────────────────────────────────────────────────

    private const CACHE_TTL_STATS = 300;      // 5 menit untuk stats
    private const CACHE_TTL_ACTIVITY = 60;    // 1 menit untuk activity (real-time)
    private const CACHE_TTL_TOP_SPAM = 300;   // 5 menit untuk top spam
    private const CACHE_TTL_LEADERBOARD = 300; // 5 menit untuk leaderboard

    // ─── Endpoints ──────────────────────────────────────────────────────────

    /**
     * Statistik keseluruhan aplikasi (overview dashboard utama).
     * Data di-cache 5 menit untuk performa optimal.
     *
     * GET /api/admin/dashboard/stats
     *
     * Return:
     *   {
     *     "users": {
     *       "total": int,
     *       "active_today": int,
     *       "new_this_week": int,
     *       "premium": int
     *     },
     *     "phones": {
     *       "total_in_db": int,
     *       "spam_numbers": int,
     *       "safe_numbers": int
     *     },
     *     "reports": {
     *       "total": int,
     *       "pending": int,
     *       "verified_today": int,
     *       "rejected_today": int
     *     },
     *     "lookups": {
     *       "total_today": int,
     *       "total_this_week": int
     *     }
     *   }
     */
    public function stats(Request $request): JsonResponse
    {
        // Cache key — satu untuk semua admin (data sama)
        $cacheKey = 'dashboard:stats:v1';

        $data = Cache::remember($cacheKey, self::CACHE_TTL_STATS, function (): array {
            return [
                'users'   => $this->getUserStats(),
                'phones'  => $this->getPhoneStats(),
                'reports' => $this->getReportStats(),
                'lookups' => $this->getLookupStats(),
            ];
        });

        return ApiResponse::success($data, 'Statistik dashboard berhasil diambil');
    }

    /**
     * Aktivitas real-time (15 menit terakhir, default).
     * Support query param: ?minutes=15|30|60
     * Data di-cache 1 menit (lebih fresh).
     *
     * GET /api/admin/dashboard/activity?minutes=30
     *
     * Return:
     *   {
     *     "recent_reports": [...],
     *     "recent_lookups_count": int,
     *     "active_users": int
     *   }
     */
    public function activity(Request $request): JsonResponse
    {
        $minutes = (int) $request->input('minutes', 15);
        // Validasi: only 15, 30, 60
        $validMinutes = [15, 30, 60];
        if (!in_array($minutes, $validMinutes, true)) {
            $minutes = 15;
        }

        $cacheKey = "dashboard:activity:{$minutes}:v1";

        $data = Cache::remember($cacheKey, self::CACHE_TTL_ACTIVITY, function () use ($minutes): array {
            $since = now()->subMinutes($minutes);

            // Recent reports — latest 10
            $recentReports = SpamReport::where('created_at', '>=', $since)
                ->with([
                    'phoneNumber:id,phone_number,normalized_number,spam_score',
                    'reporter:id,name,badge_level',
                ])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($report) => [
                    'id'           => $report->id,
                    'phone_number' => $report->phoneNumber->phone_number,
                    'reporter'     => $report->reporter?->name ?? 'Anonymous',
                    'category'     => $report->category,
                    'status'       => $report->status,
                    'created_at'   => $report->created_at->toIso8601String(),
                ])
                ->toArray();

            // Recent lookups count
            $recentLookupsCount = (int) DB::table('phone_lookups')
                ->where('looked_up_at', '>=', $since)
                ->count();

            // Active users — users yang melakukan action dalam periode ini
            $activeUsers = (int) DB::table('users')
                ->where(function ($query) use ($since) {
                    $query->where('last_login_at', '>=', $since)
                        ->orWhereHas('spamReports', fn ($q) => $q->where('created_at', '>=', $since))
                        ->orWhereHas('blockedNumbers', fn ($q) => $q->where('created_at', '>=', $since));
                })
                ->distinct()
                ->count();

            return [
                'recent_reports'     => $recentReports,
                'recent_lookups_count' => $recentLookupsCount,
                'active_users'       => $activeUsers,
            ];
        });

        return ApiResponse::success($data, "Aktivitas {$minutes} menit terakhir");
    }

    /**
     * Top 20 nomor dengan spam_score tertinggi.
     * Include jumlah laporan dan kategori dominan per nomor.
     * Data di-cache 5 menit.
     *
     * GET /api/admin/dashboard/top-spam-numbers
     *
     * Return: PhoneNumberCollection dengan metadata laporan per nomor
     */
    public function topSpamNumbers(Request $request): JsonResponse
    {
        $cacheKey = 'dashboard:top_spam_numbers:v1';

        $data = Cache::remember($cacheKey, self::CACHE_TTL_TOP_SPAM, function (): array {
            $topPhones = PhoneNumber::where('spam_score', '>', 0)
                ->with([
                    'spamReports' => fn ($q) => $q->where('status', 'verified')
                        ->select('id', 'phone_number_id', 'category'),
                ])
                ->orderByDesc('spam_score')
                ->limit(20)
                ->get();

            // Format dengan metadata laporan
            return $topPhones->map(function ($phone) {
                // Group kategori laporan yang verified
                $reports = $phone->spamReports;
                $categoryCount = $reports->groupBy('category')
                    ->map(fn ($group) => $group->count())
                    ->toArray();

                // Dominan kategori
                $dominantCategory = $categoryCount
                    ? array_key_first($categoryCount)
                    : 'unknown';

                return [
                    'id'                    => $phone->id,
                    'phone_number'          => $phone->phone_number,
                    'normalized_number'    => $phone->normalized_number,
                    'spam_score'            => $phone->spam_score,
                    'spam_level'            => $phone->spam_level,
                    'report_count'          => $reports->count(),
                    'dominant_category'     => $dominantCategory,
                    'category_breakdown'    => $categoryCount,
                    'business_profile'      => $phone->businessProfile?->business_name ?? null,
                ];
            })->toArray();
        });

        return ApiResponse::success($data, 'Top 20 nomor spam berhasil diambil');
    }

    /**
     * Top 10 kontributor laporan spam (leaderboard reporter).
     * Monitoring kualitas komunitas berdasarkan:
     *   - Total laporan yang diverifikasi
     *   - Tingkat akurasi (verified vs rejected)
     *   - Recent activity
     *
     * GET /api/admin/dashboard/reporter-leaderboard
     *
     * Return: Collection
     *   [
     *     {
     *       "user_id": int,
     *       "name": string,
     *       "badge_level": string,
     *       "verified_reports": int,
     *       "rejected_reports": int,
     *       "accuracy_rate": float (0-100),
     *       "total_points": int,
     *       "last_report": timestamp
     *     },
     *     ...
     *   ]
     */
    public function reporterLeaderboard(Request $request): JsonResponse
    {
        $cacheKey = 'dashboard:reporter_leaderboard:v1';

        $data = Cache::remember($cacheKey, self::CACHE_TTL_LEADERBOARD, function (): array {
            $leaders = DB::table('users')
                ->select(
                    'users.id',
                    'users.name',
                    'users.badge_level',
                    'users.total_points',
                )
                ->selectRaw('COUNT(CASE WHEN spam_reports.status = "verified" THEN 1 END) as verified_reports')
                ->selectRaw('COUNT(CASE WHEN spam_reports.status = "rejected" THEN 1 END) as rejected_reports')
                ->selectRaw('MAX(spam_reports.created_at) as last_report')
                ->leftJoin('spam_reports', 'users.id', '=', 'spam_reports.reporter_user_id')
                ->having('verified_reports', '>', 0)  // Only reporters dengan minimal 1 verified
                ->groupBy('users.id', 'users.name', 'users.badge_level', 'users.total_points')
                ->orderByDesc('verified_reports')
                ->limit(10)
                ->get();

            // Format dengan accuracy rate
            return $leaders->map(function ($leader) {
                $total = $leader->verified_reports + $leader->rejected_reports;
                $accuracyRate = $total > 0
                    ? round(($leader->verified_reports / $total) * 100, 2)
                    : 0;

                return [
                    'user_id'           => $leader->id,
                    'name'              => $leader->name,
                    'badge_level'       => $leader->badge_level,
                    'verified_reports'  => $leader->verified_reports,
                    'rejected_reports'  => $leader->rejected_reports,
                    'accuracy_rate'     => $accuracyRate,
                    'total_points'      => $leader->total_points,
                    'last_report'       => $leader->last_report
                        ? Carbon::parse($leader->last_report)->toIso8601String()
                        : null,
                ];
            })->toArray();
        });

        return ApiResponse::success($data, 'Top 10 reporter leaderboard berhasil diambil');
    }

    // ─── Helper Methods ──────────────────────────────────────────────────────

    /**
     * User statistics.
     *
     * @return array{
     *   total: int,
     *   active_today: int,
     *   new_this_week: int,
     *   premium: int
     * }
     */
    private function getUserStats(): array
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(7)->toDateString();

        return [
            'total'          => (int) User::count(),
            'active_today'   => (int) User::where('last_login_at', '>=', now()->startOfDay())->count(),
            'new_this_week'  => (int) User::where('created_at', '>=', $weekAgo)->count(),
            'premium'        => (int) User::where('is_premium', true)->count(),
        ];
    }

    /**
     * Phone number statistics.
     *
     * @return array{
     *   total_in_db: int,
     *   spam_numbers: int,
     *   safe_numbers: int
     * }
     */
    private function getPhoneStats(): array
    {
        return [
            'total_in_db'   => (int) PhoneNumber::count(),
            'spam_numbers'  => (int) PhoneNumber::where('spam_level', '!=', 'safe')->count(),
            'safe_numbers'  => (int) PhoneNumber::where('spam_level', 'safe')->count(),
        ];
    }

    /**
     * Spam report statistics.
     *
     * @return array{
     *   total: int,
     *   pending: int,
     *   verified_today: int,
     *   rejected_today: int
     * }
     */
    private function getReportStats(): array
    {
        $today = now()->toDateString();

        return [
            'total'             => (int) SpamReport::count(),
            'pending'           => (int) SpamReport::where('status', 'pending')->count(),
            'verified_today'    => (int) DB::table('spam_reports')
                ->where('status', 'verified')
                ->whereDate('moderated_at', $today)
                ->count(),
            'rejected_today'    => (int) DB::table('spam_reports')
                ->where('status', 'rejected')
                ->whereDate('moderated_at', $today)
                ->count(),
        ];
    }

    /**
     * Lookup statistics.
     *
     * @return array{
     *   total_today: int,
     *   total_this_week: int
     * }
     */
    private function getLookupStats(): array
    {
        $today = now()->toDateString();
        $weekAgo = now()->subDays(7)->toDateString();

        return [
            'total_today'      => (int) DB::table('phone_lookups')
                ->whereDate('looked_up_at', $today)
                ->count(),
            'total_this_week'  => (int) DB::table('phone_lookups')
                ->where('looked_up_at', '>=', $weekAgo)
                ->count(),
        ];
    }
}
