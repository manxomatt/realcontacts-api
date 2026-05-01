<?php

declare(strict_types=1);

namespace App\Services\Spam;

use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Models\SpamReportVote;
use App\Models\User;
use App\Services\Cache\PhoneCacheService;
use App\Services\User\ContributionService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * SpamReportService
 *
 * Mengelola semua operasi CRUD laporan spam:
 * membuat laporan, voting, dan query berbasis nomor/user.
 *
 * Alur store():
 *   1. Cari atau buat PhoneNumber berdasarkan nomor yang dinormalisasi
 *   2. Buat SpamReport dengan status 'pending'
 *   3. Recalculate spam score → simpan ke DB
 *   4. Beri poin kontribusi ke reporter
 *   5. Invalidate cache nomor tersebut
 */
final class SpamReportService
{
    /** Poin kontribusi yang diberikan untuk satu laporan baru */
    private const POINTS_PER_REPORT = 10;

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly SpamDetectionService $detectionService,
        private readonly ContributionService  $contributionService,
        private readonly PhoneCacheService    $cacheService,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Simpan laporan spam baru dan trigger update skor.
     *
     * Semua operasi dibungkus dalam DB transaction agar konsisten.
     * Jika recalculate atau pemberian poin gagal, laporan tidak tersimpan.
     *
     * @param  array<string, mixed> $validatedData  Data dari StoreSpamReportRequest::validatedWithReporter()
     * @return SpamReport                           Laporan yang baru dibuat (dengan relasi phoneNumber)
     */
    public function store(array $validatedData): SpamReport
    {
        // phone_number sudah dinormalisasi oleh StoreSpamReportRequest::prepareForValidation()
        $phoneNumber = (string) $validatedData['phone_number'];

        $report = DB::transaction(function () use ($validatedData, $phoneNumber): SpamReport {
            // Cari atau buat PhoneNumber — normalized_number diset dari phone_number
            $phoneModel = PhoneNumber::firstOrCreate(
                ['normalized_number' => $phoneNumber],
                [
                    'phone_number' => $phoneNumber,
                    'country_code' => substr($phoneNumber, 0, 3),   // Ambil +62 dari +62xxx
                    'spam_score'   => 0,
                    'total_reports'=> 0,
                ]
            );

            // Buat laporan spam baru dengan status pending (menunggu moderasi)
            $report = SpamReport::create([
                'phone_number_id'  => $phoneModel->id,
                'user_id'          => $validatedData['reporter_user_id'] ?? null,
                'reporter_user_id' => $validatedData['reporter_user_id'] ?? null,
                'report_type'      => $validatedData['category'],
                'description'      => $validatedData['description'] ?? null,
                'evidence_type'    => $validatedData['evidence_type'] ?? null,
                'status'           => 'pending',
            ]);

            // Increment total_reports di PhoneNumber
            $phoneModel->increment('total_reports');

            return $report;
        });

        // Recalculate spam score — dilakukan di luar transaction agar tidak blocking lama
        // #[\NoDiscard] — wajib gunakan return value
        $updatedPhone = $this->detectionService->recalculateAndSave($phoneNumber);

        // Beri poin kontribusi ke reporter jika ada akun
        if (isset($validatedData['reporter_user_id'])) {
            $reporter = User::find($validatedData['reporter_user_id']);

            if ($reporter !== null) {
                $this->contributionService->addPoints(
                    user: $reporter,
                    points: self::POINTS_PER_REPORT,
                    reason: 'spam_report_submitted',
                );
            }
        }

        // Cache sudah diinvalidasi oleh recalculateAndSave(),
        // tapi lakukan sekali lagi untuk memastikan konsistensi
        $this->cacheService->invalidate($phoneNumber);

        // Load relasi agar tidak lazy-load di controller
        $report->load(['phoneNumber', 'reporter']);

        return $report;
    }

    /**
     * Vote pada sebuah laporan spam (upvote atau downvote).
     *
     * Aturan:
     *   - Satu user satu vote per laporan (unique constraint di DB)
     *   - Jika sudah vote dengan tipe yang sama → batalkan (toggle off)
     *   - Jika sudah vote dengan tipe berbeda → ganti vote
     *
     * @param  SpamReport $report  Laporan yang akan di-vote
     * @param  User       $user    User yang melakukan voting
     * @param  string     $type    'upvote' | 'downvote'
     * @return array{upvotes: int, downvotes: int}
     */
    public function vote(SpamReport $report, User $user, string $type): array
    {
        // Cek apakah user sudah pernah vote laporan ini
        $existing = SpamReportVote::where('spam_report_id', $report->id)
            ->where('user_id', $user->id)
            ->first();

        DB::transaction(function () use ($report, $user, $type, $existing): void {
            if ($existing === null) {
                // Belum pernah vote — buat vote baru
                SpamReportVote::create([
                    'spam_report_id' => $report->id,
                    'user_id'        => $user->id,
                    'type'           => $type,
                ]);

                $this->applyVoteDelta($report, $type, delta: +1);

            } elseif ($existing->type === $type) {
                // Vote yang sama → batalkan (toggle off)
                $existing->delete();
                $this->applyVoteDelta($report, $type, delta: -1);

            } else {
                // Vote berbeda → ganti
                $oldType = $existing->type;
                $existing->update(['type' => $type]);

                // Kurangi counter lama, tambah counter baru
                $this->applyVoteDelta($report, $oldType, delta: -1);
                $this->applyVoteDelta($report, $type, delta: +1);
            }
        });

        // Refresh untuk mendapatkan counter terbaru
        $report->refresh();

        return [
            'upvotes'   => $report->upvotes,
            'downvotes' => $report->downvotes,
        ];
    }

    /**
     * Ambil laporan terverifikasi untuk sebuah nomor telepon (paginated).
     *
     * Eager load reporter dengan badge_level agar tidak N+1 di resource.
     * Diurutkan dari yang terbaru.
     *
     * @param  string $phoneNumber  Nomor yang sudah dinormalisasi
     * @param  int    $perPage      Jumlah item per halaman
     * @return LengthAwarePaginator
     */
    public function getByPhoneNumber(string $phoneNumber, int $perPage = 10): LengthAwarePaginator
    {
        return SpamReport::whereHas(
            'phoneNumber',
            fn($q) => $q->where('normalized_number', $phoneNumber)
        )
        ->where('status', 'verified')
        ->with([
            'reporter:id,name,badge_level',   // Hanya field yang diperlukan
        ])
        ->latest()
        ->paginate($perPage);
    }

    /**
     * Ambil riwayat laporan yang dibuat oleh user tertentu (paginated).
     *
     * Menampilkan semua status (pending, verified, rejected) agar
     * user bisa memonitor laporan mereka sendiri.
     *
     * @param  User $user     User yang laporan-nya ingin diambil
     * @param  int  $perPage  Jumlah item per halaman
     * @return LengthAwarePaginator
     */
    public function getUserReports(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return SpamReport::where('reporter_user_id', $user->id)
            ->with(['phoneNumber:id,phone_number,spam_score'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Cek apakah user sudah melaporkan nomor ini dalam 24 jam terakhir.
     *
     * Digunakan oleh StoreSpamReportRequest::withValidator() untuk mencegah
     * laporan duplikat dalam waktu singkat.
     *
     * @param  string $phoneNumber  Nomor yang sudah dinormalisasi
     * @param  int    $userId       ID user pelapor
     * @return bool                 true jika sudah ada laporan dalam 24 jam
     */
    public function hasDuplicateReport(string $phoneNumber, int $userId): bool
    {
        return SpamReport::whereHas(
            'phoneNumber',
            fn($q) => $q->where('normalized_number', $phoneNumber)
        )
        ->where('reporter_user_id', $userId)
        ->where('created_at', '>=', now()->subHours(24))
        ->exists();
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Terapkan perubahan delta (+1 atau -1) ke counter upvotes/downvotes.
     *
     * @param  SpamReport $report  Model yang akan diupdate
     * @param  string     $type    'upvote' | 'downvote'
     * @param  int        $delta   +1 (tambah) atau -1 (kurangi)
     */
    private function applyVoteDelta(SpamReport $report, string $type, int $delta): void
    {
        $column = $type === 'upvote' ? 'upvotes' : 'downvotes';

        if ($delta > 0) {
            $report->increment($column);
        } else {
            // Pastikan tidak menjadi negatif
            $report->decrement($column, 1);
            $report->{$column} = max(0, $report->{$column});
            $report->save();
        }
    }
}
