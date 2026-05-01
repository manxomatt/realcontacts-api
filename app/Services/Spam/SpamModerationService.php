<?php

declare(strict_types=1);

namespace App\Services\Spam;

use App\Models\SpamReport;
use App\Models\User;
use App\Services\User\ContributionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * SpamModerationService
 *
 * Mengelola proses moderasi laporan spam oleh admin.
 *
 * Alur moderasi:
 *   approve() → status='verified' → recalculate score → +20 poin reporter
 *   reject()  → status='rejected' → recalculate score → -5 poin jika rejection_rate > 50%
 *
 * Auto-moderation (tanpa campur tangan admin):
 *   upvotes >= 10 dan downvotes <= 2 → auto-approve
 *   downvotes >= 5 dan upvotes <= 1  → auto-reject
 */
final class SpamModerationService
{
    // ─── Konfigurasi poin kontribusi ──────────────────────────────────────────

    /** Bonus poin jika laporan diverifikasi */
    private const POINTS_APPROVE = 20;

    /** Pengurangan poin jika reporter sering salah lapor */
    private const POINTS_REJECT_PENALTY = 5;

    /** Threshold rejection_rate untuk penalti poin (persen) */
    private const REJECTION_RATE_THRESHOLD = 50.0;

    // ─── Threshold auto-moderation ────────────────────────────────────────────

    private const AUTO_APPROVE_UPVOTES   = 10;
    private const AUTO_APPROVE_DOWNVOTES = 2;
    private const AUTO_REJECT_DOWNVOTES  = 5;
    private const AUTO_REJECT_UPVOTES    = 1;

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly SpamDetectionService $detectionService,
        private readonly ContributionService  $contributionService,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Setujui laporan spam — ubah status ke 'verified'.
     *
     * Alur:
     *   1. Update status + simpan info moderator dalam satu query
     *   2. Recalculate spam score nomor yang dilaporkan
     *   3. Beri bonus poin ke reporter
     *
     * @param  SpamReport $report    Laporan yang akan disetujui
     * @param  User       $moderator Admin yang melakukan approve
     * @return SpamReport            Model yang sudah diperbarui
     */
    public function approve(SpamReport $report, User $moderator): SpamReport
    {
        // Update status dan metadata moderasi dalam satu transaksi
        DB::transaction(function () use ($report, $moderator): void {
            $report->update([
                'status'       => 'verified',
                'moderator_id' => $moderator->id,
                'moderated_at' => now(),
            ]);
        });

        // Recalculate spam score — #[\NoDiscard] wajib dikonsumsi
        $_ = $this->detectionService->recalculateAndSave(
            $report->phoneNumber->normalized_number
        );

        // Beri bonus poin ke reporter jika ada
        if ($report->reporter_user_id !== null) {
            $reporter = $report->reporter;

            if ($reporter !== null) {
                $this->contributionService->addPoints(
                    user: $reporter,
                    points: self::POINTS_APPROVE,
                    reason: 'spam_report_verified',
                );
            }
        }

        $report->refresh();

        return $report;
    }

    /**
     * Tolak laporan spam — ubah status ke 'rejected'.
     *
     * Penalti poin diberikan hanya jika reporter memiliki rejection_rate > 50%,
     * untuk menghindari hukuman pada reporter yang sesekali salah.
     *
     * @param  SpamReport $report    Laporan yang akan ditolak
     * @param  User       $moderator Admin yang melakukan reject
     * @param  string     $reason    Alasan penolakan (wajib, min 10 karakter)
     * @return SpamReport            Model yang sudah diperbarui
     */
    public function reject(SpamReport $report, User $moderator, string $reason): SpamReport
    {
        DB::transaction(function () use ($report, $moderator, $reason): void {
            $report->update([
                'status'           => 'rejected',
                'moderator_id'     => $moderator->id,
                'moderated_at'     => now(),
                'rejection_reason' => $reason,
            ]);
        });

        // Recalculate score agar nomor yang sudah banyak laporan rejected
        // tidak memiliki skor yang inflated
        $_ = $this->detectionService->recalculateAndSave(
            $report->phoneNumber->normalized_number
        );

        // Beri penalti poin hanya jika reporter sering salah lapor
        if ($report->reporter_user_id !== null) {
            $reporter = $report->reporter;

            if ($reporter !== null) {
                $stats = $this->getReporterStats($reporter);

                if ($stats['rejection_rate'] > self::REJECTION_RATE_THRESHOLD) {
                    $this->contributionService->deductPoints(
                        user: $reporter,
                        points: self::POINTS_REJECT_PENALTY,
                        reason: 'spam_report_rejected_penalty',
                    );
                }
            }
        }

        $report->refresh();

        return $report;
    }

    /**
     * Ambil semua laporan yang menunggu moderasi (paginated).
     *
     * Diurutkan berdasarkan upvotes tertinggi lebih dulu — laporan yang
     * sudah divalidasi komunitas diprioritaskan untuk diperiksa moderator.
     *
     * @param  int $perPage Jumlah item per halaman
     * @return LengthAwarePaginator
     */
    public function getPendingReports(int $perPage = 20): LengthAwarePaginator
    {
        return SpamReport::where('status', 'pending')
            ->with([
                'phoneNumber:id,phone_number,normalized_number,spam_score',
                'reporter:id,name,badge_level',
            ])
            ->orderByDesc('upvotes')
            ->orderBy('created_at')        // Tiebreaker: yang lebih lama dulu
            ->paginate($perPage);
    }

    /**
     * Statistik akurasi laporan seorang reporter.
     *
     * Digunakan oleh reject() untuk menentukan apakah reporter
     * layak mendapat penalti poin.
     *
     * @param  User $user
     * @return array{
     *   total_reports:  int,
     *   verified:       int,
     *   rejected:       int,
     *   pending:        int,
     *   accuracy_rate:  float,
     *   rejection_rate: float
     * }
     */
    public function getReporterStats(User $user): array
    {
        // Ambil jumlah per status dalam satu query GROUP BY
        $counts = SpamReport::where('reporter_user_id', $user->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $verified = (int) ($counts['verified'] ?? 0);
        $rejected = (int) ($counts['rejected'] ?? 0);
        $pending  = (int) ($counts['pending']  ?? 0);
        $total    = $verified + $rejected + $pending;

        // Hindari division by zero — jika belum ada laporan, rate = 0
        $accuracyRate  = $total > 0 ? round(($verified / $total) * 100, 1) : 0.0;
        $rejectionRate = $total > 0 ? round(($rejected / $total) * 100, 1) : 0.0;

        return [
            'total_reports'  => $total,
            'verified'       => $verified,
            'rejected'       => $rejected,
            'pending'        => $pending,
            'accuracy_rate'  => $accuracyRate,
            'rejection_rate' => $rejectionRate,
        ];
    }

    /**
     * Auto-moderasi berdasarkan sinyal voting komunitas.
     *
     * Dipanggil setelah setiap upvote/downvote di SpamReportService::vote().
     * Jika memenuhi threshold, proses moderasi dijalankan otomatis menggunakan
     * user sistem (moderator_id = null karena tidak ada admin yang mereview).
     *
     * Threshold:
     *   Auto-approve : upvotes >= 10 DAN downvotes <= 2
     *   Auto-reject  : downvotes >= 5 DAN upvotes <= 1
     *
     * @param  SpamReport $report
     * @return string|null 'approved' | 'rejected' | null (perlu review manual)
     */
    public function autoModerate(SpamReport $report): ?string
    {
        // Hanya proses laporan yang masih pending
        if ($report->status !== 'pending') {
            return null;
        }

        // Kondisi auto-approve
        if ($report->upvotes >= self::AUTO_APPROVE_UPVOTES
            && $report->downvotes <= self::AUTO_APPROVE_DOWNVOTES) {

            // Gunakan update langsung (tanpa moderator manusia)
            DB::transaction(function () use ($report): void {
                $report->update([
                    'status'       => 'verified',
                    'moderated_at' => now(),
                    // moderator_id tetap null — proses otomatis
                ]);
            });

            $_ = $this->detectionService->recalculateAndSave(
                $report->phoneNumber->normalized_number
            );

            // Beri poin ke reporter (setengah dari approve manual)
            if ($report->reporter_user_id !== null && $report->reporter !== null) {
                $this->contributionService->addPoints(
                    user: $report->reporter,
                    points: (int) round(self::POINTS_APPROVE / 2),
                    reason: 'spam_report_auto_verified',
                );
            }

            return 'approved';
        }

        // Kondisi auto-reject
        if ($report->downvotes >= self::AUTO_REJECT_DOWNVOTES
            && $report->upvotes <= self::AUTO_REJECT_UPVOTES) {

            DB::transaction(function () use ($report): void {
                $report->update([
                    'status'           => 'rejected',
                    'moderated_at'     => now(),
                    'rejection_reason' => 'Auto-ditolak: komunitas menandai sebagai tidak valid.',
                    // moderator_id tetap null — proses otomatis
                ]);
            });

            $_ = $this->detectionService->recalculateAndSave(
                $report->phoneNumber->normalized_number
            );

            return 'rejected';
        }

        // Tidak memenuhi threshold — perlu review manual
        return null;
    }
}
