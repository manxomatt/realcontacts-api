<?php

declare(strict_types=1);

namespace App\Services\Spam;

use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Services\Cache\PhoneCacheService;
use App\Services\Phone\PhoneNormalizerService;
use Illuminate\Support\Collection;

/**
 * SpamDetectionService
 *
 * Service inti untuk mendeteksi dan menghitung skor spam setiap nomor telepon.
 *
 * Komponen skor (total 100 poin):
 *   A. Volume laporan  — bobot 40% (maks 80 poin → dikali 0.5 = 40)
 *   B. Kategori dominan — bobot 30% (maks 30 poin)
 *   C. Kredibilitas    — bobot 20% (rata-rata credibility per laporan)
 *   D. Faktor waktu    — bobot 10% (time decay dari laporan 7 hari terakhir)
 *
 * Hasil di-clamp antara 0 dan 100.
 */
final class SpamDetectionService
{
    // ─── Tabel bobot kategori ──────────────────────────────────────────────────

    /**
     * Poin bonus per kategori laporan.
     * Kategori tidak tercantum di sini mendapat 0 poin bonus.
     *
     * @var array<string, int>
     */
    private const CATEGORY_WEIGHTS = [
        'fraud_bank'     => 30,
        'fraud_prize'    => 30,
        'debt_collector' => 25,
        'harassment'     => 20,
        'robo_call'      => 18,
        'telemarketing'  => 15,
        'spam'           => 12,
        'survey'         => 10,
        'unknown_spam'   => 10,
    ];

    // ─── Threshold volume laporan ─────────────────────────────────────────────

    /** Poin mentah faktor volume (sebelum dibagi 2 menjadi 40% dari 100) */
    private const VOLUME_SCORES = [
        51 => 80,
        21 => 60,
        6  => 40,
        1  => 20,
        0  => 0,
    ];

    // ─── Konstanta waktu ──────────────────────────────────────────────────────

    /** Laporan dalam N hari terakhir dianggap "fresh" untuk time decay */
    private const RECENT_DAYS = 7;

    /** Batas waktu recalculate (detik) — lebih dari ini wajib recalculate */
    private const RECALCULATE_THRESHOLD_SECONDS = 3600;   // 1 jam

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly PhoneCacheService      $cacheService,
        private readonly PhoneNormalizerService $normalizer,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Hitung spam score untuk sebuah nomor telepon (0–100).
     *
     * Menggunakan pipe operator |> PHP 8.5 untuk chaining setiap komponen skor.
     * Setiap komponen dihitung independen lalu dijumlahkan, kemudian di-clamp.
     *
     * @param  string $phoneNumber Nomor dalam format apapun (akan dinormalisasi)
     * @return int                 Skor spam 0–100
     */
    public function calculateScore(string $phoneNumber): int
    {
        $normalized = $this->normalizer->normalize($phoneNumber);

        // Ambil semua laporan yang sudah terverifikasi untuk nomor ini
        $reports = SpamReport::whereHas(
            'phoneNumber',
            fn($q) => $q->where('normalized_number', $normalized)
        )
        ->where('status', 'verified')
        ->get();

        // Jika tidak ada laporan, skor = 0
        if ($reports->isEmpty()) {
            return 0;
        }

        // ── Hitung setiap komponen via pipe |> ────────────────────────────────

        // A. Faktor volume (0–40 poin)
        $volumeScore = $reports->count()
            |> (fn(int $count): int => $this->resolveVolumeScore($count))
            |> (fn(int $raw): int   => (int) round($raw * 0.5));   // 80 maks → 40

        // B. Faktor kategori dominan (0–30 poin)
        $categoryScore = $reports
            |> (fn(Collection $r): string => $this->resolveDominantCategory($r))
            |> (fn(string $cat): int      => self::CATEGORY_WEIGHTS[$cat] ?? 0);

        // C. Faktor kredibilitas laporan (0–20 poin)
        $credibilityScore = $reports
            |> (fn(Collection $r): float => $this->resolveAvgCredibility($r))
            |> (fn(float $avg): int      => (int) round($avg * 0.20));   // 0–100 → 0–20

        // D. Faktor waktu / time decay (pengali, bukan tambahan flat)
        $recentCount = $reports
            ->filter(fn(SpamReport $r): bool =>
                $r->created_at !== null
                && $r->created_at->isAfter(now()->subDays(self::RECENT_DAYS))
            )
            ->count();

        // Skor dasar sebelum time decay
        $baseScore = $volumeScore + $categoryScore + $credibilityScore;

        // Time decay: setiap laporan recent menambah 10% dari base score
        // Dibatasi max 10 poin tambahan agar tidak overflow
        $decayBonus = (int) min(10, round($baseScore * $recentCount * 0.05));

        $finalScore = $baseScore + $decayBonus;

        // Clamp 0–100
        return max(0, min(100, $finalScore));
    }

    /**
     * Tentukan kategori laporan yang paling dominan untuk sebuah nomor.
     *
     * Menghitung frekuensi tiap report_type, lalu mengambil yang tertinggi.
     * Menggunakan array_first() PHP 8.5 (1 argumen) setelah arsort().
     *
     * @param  string $phoneNumber
     * @return string Nama kategori paling banyak dilaporkan
     */
    public function getDominantCategory(string $phoneNumber): string
    {
        $normalized = $this->normalizer->normalize($phoneNumber);

        $reports = SpamReport::whereHas(
            'phoneNumber',
            fn($q) => $q->where('normalized_number', $normalized)
        )
        ->where('status', 'verified')
        ->pluck('report_type')
        ->toArray();

        if (empty($reports)) {
            return 'unknown_spam';
        }

        // Hitung frekuensi setiap kategori
        $frequency = array_count_values($reports);

        // Urutkan dari frekuensi tertinggi ke terendah
        arsort($frequency);

        // array_first() PHP 8.5 — 1 argumen, ambil elemen pertama (nilai frekuensi tertinggi)
        // Kita butuh key-nya, bukan value — gunakan array_key_first()
        return (string) (array_key_first($frequency) ?? 'unknown_spam');
    }

    /**
     * Konversi skor numerik ke label level spam.
     *
     *   < 30  → 'safe'
     *   30–69 → 'warning'
     *   >= 70 → 'danger'
     */
    public function getSpamLevel(int $score): string
    {
        return match(true) {
            $score >= 70 => 'danger',
            $score >= 30 => 'warning',
            default      => 'safe',
        };
    }

    /**
     * Hitung ulang spam score dan simpan ke database + invalidate cache.
     *
     * #[\NoDiscard] → return value wajib dipakai oleh pemanggil.
     * Jangan panggil method ini dan buang hasilnya karena itu menandakan
     * ada cache invalidation yang sudah terjadi tapi model tidak dipakai.
     *
     * @param  string $phoneNumber
     * @return PhoneNumber Model yang sudah diperbarui spam_score-nya
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    #[\NoDiscard("Gunakan PhoneNumber yang dikembalikan — cache sudah diinvalidasi")]
    public function recalculateAndSave(string $phoneNumber): PhoneNumber
    {
        $normalized = $this->normalizer->normalize($phoneNumber);

        /** @var PhoneNumber $phone */
        $phone = PhoneNumber::where('normalized_number', $normalized)->firstOrFail();

        // Hitung skor terbaru
        $newScore = $this->calculateScore($phoneNumber);

        // Simpan ke database + update last_updated
        $phone->update([
            'spam_score'   => $newScore,
            'last_updated' => now(),
        ]);

        // Refresh agar properti model sinkron dengan DB
        $phone->refresh();

        // Invalidasi cache agar data stale tidak dikembalikan
        $this->cacheService->invalidate($phoneNumber);

        return $phone;
    }

    /**
     * Cek apakah skor spam perlu dihitung ulang.
     *
     * Recalculate diperlukan jika:
     *   1. last_updated lebih dari 1 jam yang lalu, ATAU
     *   2. Ada laporan baru yang masuk setelah last_updated terakhir
     *
     * Digunakan sebelum memanggil recalculateAndSave() untuk menghindari
     * kalkulasi yang tidak perlu.
     */
    public function shouldRecalculate(PhoneNumber $phone): bool
    {
        // Belum pernah dihitung — wajib recalculate
        if ($phone->last_updated === null) {
            return true;
        }

        // Sudah lebih dari 1 jam sejak kalkulasi terakhir
        $ageInSeconds = now()->diffInSeconds($phone->last_updated);
        if ($ageInSeconds > self::RECALCULATE_THRESHOLD_SECONDS) {
            return true;
        }

        // Ada laporan baru sejak last_updated
        $hasNewReports = SpamReport::where('phone_number_id', $phone->id)
            ->where('status', 'verified')
            ->where('created_at', '>', $phone->last_updated)
            ->exists();

        return $hasNewReports;
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Tentukan poin mentah faktor volume dari jumlah laporan.
     *
     * Threshold diurutkan dari tertinggi ke terendah.
     * array_filter memilih semua threshold yang <= $count,
     * lalu array_first() mengambil nilai threshold tertinggi yang terpenuhi.
     *
     * @param  int $count  Jumlah laporan verified
     * @return int         Poin mentah volume (sebelum dikali 0.5)
     */
    private function resolveVolumeScore(int $count): int
    {
        // Saring threshold yang sudah terpenuhi oleh jumlah laporan
        $qualifiedScores = array_filter(
            self::VOLUME_SCORES,
            fn(int $threshold): bool => $count >= $threshold,
            ARRAY_FILTER_USE_KEY
        );

        // array_first() PHP 8.5 — 1 argumen, ambil poin dari threshold tertinggi
        return array_first($qualifiedScores) ?? 0;
    }

    /**
     * Hitung rata-rata credibility score dari semua laporan.
     *
     * Credibility dihitung sebagai: upvotes / (upvotes + downvotes) * 100.
     * Laporan tanpa votes mendapat nilai default 50 (netral).
     *
     * @param  Collection<int, SpamReport> $reports
     * @return float  Rata-rata credibility 0–100
     */
    private function resolveAvgCredibility(Collection $reports): float
    {
        $scores = $reports->map(function (SpamReport $report): float {
            $total = $report->upvotes + $report->downvotes;

            if ($total === 0) {
                return 50.0;   // Netral jika belum ada voting
            }

            return ($report->upvotes / $total) * 100;
        });

        return $scores->avg() ?? 0.0;
    }

    /**
     * Tentukan kategori dominan dari Collection laporan.
     *
     * Versi internal yang menerima Collection langsung (dipakai oleh calculateScore
     * agar tidak perlu query ulang ke database).
     *
     * @param  Collection<int, SpamReport> $reports
     * @return string
     */
    private function resolveDominantCategory(Collection $reports): string
    {
        $types = $reports->pluck('report_type')->toArray();

        if (empty($types)) {
            return 'unknown_spam';
        }

        $frequency = array_count_values($types);
        arsort($frequency);

        return (string) (array_key_first($frequency) ?? 'unknown_spam');
    }
}
