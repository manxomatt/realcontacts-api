<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\ContributionLog;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * ContributionService
 *
 * Mengelola sistem poin dan badge komunitas RealContacts.
 *
 * Alur badge:
 *   0–100 poin   → newcomer
 *   101–500 poin → contributor
 *   501+ poin    → guardian
 *
 * Setiap perubahan poin dicatat di tabel contribution_logs
 * untuk keperluan audit trail dan perhitungan leaderboard.
 */
final class ContributionService
{
    // ─── Konfigurasi Badge ────────────────────────────────────────────────────

    /**
     * Map badge berdasarkan batas minimum poin.
     * Urutan penting: dari tertinggi ke terendah (untuk array_first).
     *
     * @var array<int, string>
     */
    private const BADGE_THRESHOLDS = [
        501 => 'guardian',
        101 => 'contributor',
        0   => 'newcomer',
    ];

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Tambah poin kontribusi ke user.
     *
     * Alur:
     *   1. Tambah contribution_points langsung di DB (atomic via increment)
     *   2. Ambil total terbaru
     *   3. Log di contribution_logs
     *   4. Cek apakah badge naik level
     *
     * @param  positive-int $points  Jumlah poin yang ditambahkan (harus > 0)
     * @param  string       $reason  Alasan penambahan (contoh: 'spam_report_verified')
     * @return int Total poin setelah penambahan
     */
    public function addPoints(User $user, int $points, string $reason): int
    {
        // Tambah poin secara atomic di database
        $user->increment('contribution_points', $points);

        // Refresh model untuk mendapat nilai terbaru
        $user->refresh();

        $totalAfter = (int) $user->contribution_points;

        // Catat log transaksi poin
        $this->writeLog($user->id, $points, $reason, $totalAfter);

        // Cek dan proses kenaikan badge
        $this->checkBadgeUpgrade($user);

        return $totalAfter;
    }

    /**
     * Kurangi poin kontribusi user.
     *
     * Poin tidak bisa menjadi negatif — minimum adalah 0.
     * Jika poin yang dikurangi melebihi saldo, maka total dikunci ke 0.
     *
     * @param  positive-int $points  Jumlah poin yang dikurangi (harus > 0)
     * @param  string       $reason  Alasan pengurangan (contoh: 'spam_report_rejected')
     * @return int Total poin setelah pengurangan
     */
    public function deductPoints(User $user, int $points, string $reason): int
    {
        $current = (int) $user->contribution_points;

        // Pastikan tidak negatif
        $actualDeduction = min($points, $current);
        $totalAfter      = $current - $actualDeduction;

        // Update ke nilai baru
        $user->update(['contribution_points' => $totalAfter]);

        // Catat log dengan nilai negatif (tanda pengurangan)
        $this->writeLog($user->id, -$actualDeduction, $reason, $totalAfter);

        return $totalAfter;
    }

    /**
     * Cek apakah user naik badge berdasarkan total poin saat ini.
     *
     * Hanya update jika badge baru LEBIH TINGGI dari badge sekarang
     * (tidak downgrade). Jika naik, kirim notifikasi ke user.
     *
     * @return string|null Badge baru jika naik level, null jika tidak
     */
    public function checkBadgeUpgrade(User $user): ?string
    {
        $currentBadge = (string) ($user->badge_level ?? 'newcomer');
        $totalPoints  = (int) $user->contribution_points;

        $newBadge = $this->resolveBadge($totalPoints);

        // Tidak ada perubahan atau tidak naik level
        if ($newBadge === $currentBadge) {
            return null;
        }

        // Pastikan hanya naik (tidak downgrade badge)
        $currentRank = $this->badgeRank($currentBadge);
        $newRank     = $this->badgeRank($newBadge);

        if ($newRank <= $currentRank) {
            return null;
        }

        // Update badge di database
        $user->update(['badge_level' => $newBadge]);
        $user->refresh();

        // Kirim notifikasi selamat
        $this->sendBadgeUpgradeNotification($user, $newBadge);

        return $newBadge;
    }

    /**
     * Ambil daftar top contributors berdasarkan periode.
     *
     * - 'weekly'  : poin dikumpulkan dalam 7 hari terakhir (dari contribution_logs)
     * - 'monthly' : poin dikumpulkan dalam 30 hari terakhir (dari contribution_logs)
     * - 'alltime' : ambil langsung dari kolom contribution_points di tabel users
     *
     * @param  'weekly'|'monthly'|'alltime' $period
     * @param  positive-int                 $limit  Jumlah user yang ditampilkan
     * @return Collection<int, object>
     */
    public function getLeaderboard(string $period = 'monthly', int $limit = 10): Collection
    {
        return match($period) {
            'weekly'  => $this->leaderboardFromLogs(days: 7,  limit: $limit),
            'monthly' => $this->leaderboardFromLogs(days: 30, limit: $limit),
            default   => $this->leaderboardAllTime(limit: $limit),
        };
    }

    /**
     * Ambil posisi dan detail ranking user di leaderboard all-time.
     *
     * @return array{
     *   rank:          int,
     *   total_points:  int,
     *   badge:         string,
     *   next_badge:    string|null,
     *   points_needed: int|null
     * }
     */
    public function getUserRank(User $user): array
    {
        $totalPoints = (int) $user->contribution_points;
        $badge       = (string) ($user->badge_level ?? 'newcomer');

        // Hitung rank berdasarkan jumlah user dengan poin lebih banyak + 1
        $rank = (int) User::where('contribution_points', '>', $totalPoints)->count() + 1;

        // Tentukan badge berikutnya dan poin yang dibutuhkan
        [$nextBadge, $pointsNeeded] = $this->resolveNextBadge($totalPoints);

        return [
            'rank'          => $rank,
            'total_points'  => $totalPoints,
            'badge'         => $badge,
            'next_badge'    => $nextBadge,
            'points_needed' => $pointsNeeded,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Catat satu baris log ke tabel contribution_logs.
     */
    private function writeLog(int $userId, int $points, string $reason, int $totalAfter): void
    {
        ContributionLog::create([
            'user_id'     => $userId,
            'points'      => $points,
            'reason'      => $reason,
            'total_after' => $totalAfter,
        ]);
    }

    /**
     * Tentukan badge yang sesuai berdasarkan total poin.
     *
     * Menggunakan array_first() PHP 8.5 (1 argumen) dengan array_filter()
     * untuk mencari ambang batas tertinggi yang terpenuhi.
     *
     * BADGE_THRESHOLDS diurutkan dari nilai tertinggi ke terendah
     * sehingga array_first() selalu mengambil badge paling tinggi yang qualify.
     */
    private function resolveBadge(int $points): string
    {
        // Saring hanya threshold yang <= poin user
        $qualifiedBadges = array_filter(
            self::BADGE_THRESHOLDS,
            fn(int $threshold): bool => $points >= $threshold,
            ARRAY_FILTER_USE_KEY
        );

        // array_first() PHP 8.5 — ambil badge pertama (threshold tertinggi yang terpenuhi)
        return array_first($qualifiedBadges) ?? 'newcomer';
    }

    /**
     * Tentukan badge berikutnya dan poin yang dibutuhkan untuk naik.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function resolveNextBadge(int $points): array
    {
        // Threshold berikutnya di atas poin saat ini (filter yang BELUM terpenuhi)
        $nextThresholds = array_filter(
            array_keys(self::BADGE_THRESHOLDS),
            fn(int $threshold): bool => $threshold > $points
        );

        if (empty($nextThresholds)) {
            // Sudah di badge tertinggi
            return [null, null];
        }

        // Ambil threshold terendah yang belum terpenuhi (paling dekat)
        $nextThreshold = min($nextThresholds);
        $nextBadge     = self::BADGE_THRESHOLDS[$nextThreshold];
        $pointsNeeded  = $nextThreshold - $points;

        return [$nextBadge, $pointsNeeded];
    }

    /**
     * Return nilai numerik badge untuk perbandingan level.
     * Digunakan untuk memastikan tidak terjadi downgrade badge.
     */
    private function badgeRank(string $badge): int
    {
        return match($badge) {
            'guardian'    => 3,
            'contributor' => 2,
            'newcomer'    => 1,
            default       => 0,
        };
    }

    /**
     * Leaderboard dari tabel contribution_logs dalam rentang N hari terakhir.
     *
     * Query: GROUP BY user_id → SUM(points) → JOIN users → ORDER BY total DESC.
     * Hanya menghitung entri dengan points > 0 (abaikan pengurangan).
     *
     * @return Collection<int, object>
     */
    private function leaderboardFromLogs(int $days, int $limit): Collection
    {
        return DB::table('contribution_logs')
            ->select([
                'users.id',
                'users.name',
                'users.badge_level',
                DB::raw('SUM(contribution_logs.points) as total_points'),
            ])
            ->join('users', 'users.id', '=', 'contribution_logs.user_id')
            ->where('contribution_logs.created_at', '>=', now()->subDays($days))
            ->where('contribution_logs.points', '>', 0)          // Abaikan pengurangan
            ->groupBy('users.id', 'users.name', 'users.badge_level')
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get();
    }

    /**
     * Leaderboard all-time langsung dari kolom contribution_points di tabel users.
     *
     * Lebih cepat karena tidak perlu agregasi contribution_logs.
     *
     * @return Collection<int, object>
     */
    private function leaderboardAllTime(int $limit): Collection
    {
        return DB::table('users')
            ->select(['id', 'name', 'badge_level', 'contribution_points as total_points'])
            ->orderByDesc('contribution_points')
            ->limit($limit)
            ->get();
    }

    /**
     * Kirim notifikasi selamat kepada user yang naik badge.
     *
     * Menggunakan on-demand notification jika class notifikasi tersedia.
     * Silent fail agar tidak mengganggu alur utama penambahan poin.
     */
    private function sendBadgeUpgradeNotification(User $user, string $newBadge): void
    {
        // Notifikasi dikirim via notifiable (user langsung)
        // Class BadgeUpgradeNotification diimplementasi terpisah
        $notificationClass = 'App\\Notifications\\BadgeUpgradeNotification';

        if (class_exists($notificationClass)) {
            $user->notify(new $notificationClass($newBadge));
        }
    }
}
