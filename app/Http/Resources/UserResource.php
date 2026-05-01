<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Resource untuk profil user yang sedang login.
 * TIDAK mengekspos: id, email, password, remember_token,
 * nomor telepon lengkap, created_at raw, updated_at.
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    // Peta badge_level lama ke label role yang lebih deskriptif
    private const BADGE_LABELS = [
        'bronze'   => 'newcomer',
        'silver'   => 'contributor',
        'gold'     => 'contributor',
        'platinum' => 'guardian',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // ─── Identitas ────────────────────────────────────────────────────

            'name' => $this->name,

            // Nomor telepon disamarkan: tampilkan hanya 4 digit terakhir
            // Contoh: +6281234567890 → +628****7890
            'phone_number' => $this->maskPhoneNumber($this->phone_number),

            // ─── Badge & Level ─────────────────────────────────────────────────

            'badge_level' => [
                // Key internal (bronze/silver/gold/platinum) untuk logika frontend
                'key'   => $this->badge_level,
                // Label tampilan yang lebih deskriptif untuk user
                'label' => self::BADGE_LABELS[$this->badge_level] ?? $this->badge_level,
            ],

            'contribution_points' => $this->contribution_points,

            // ─── Premium ───────────────────────────────────────────────────────

            'is_premium' => $this->is_premium,

            // Format tanggal lokal — null jika bukan premium
            'premium_expires_at' => $this->is_premium && $this->premium_expires_at
                ? $this->premium_expires_at
                    ->locale('id')
                    ->isoFormat('D MMMM YYYY')
                : null,

            // ─── Keanggotaan ───────────────────────────────────────────────────

            // Format "Januari 2024" — hanya bulan dan tahun bergabung
            'member_since' => $this->created_at
                ->locale('id')
                ->isoFormat('MMMM YYYY'),

            // ─── Statistik Kontribusi ──────────────────────────────────────────
            //
            // whenLoaded() — TIDAK auto-query ke DB.
            // Controller wajib memanggil ->load('spamReports') secara eksplisit.
            // Jika tidak di-load, key 'stats' tidak muncul di response.
            'stats' => $this->whenLoaded('spamReports', function () {
                $total    = $this->spamReports->count();
                $verified = $this->spamReports
                    ->where('status', 'verified')
                    ->count();

                return [
                    'total_reports'    => $total,
                    'verified_reports' => $verified,

                    // Persentase akurasi — null jika belum ada laporan (hindari division by zero)
                    'accuracy_rate' => $total > 0
                        ? round($verified / $total * 100, 1)
                        : null,
                ];
            }),
        ];
    }

    /**
     * Top-level wrapper sesuai format standar RealContacts.
     */
    public function toResponse($request): mixed
    {
        return response()->json([
            'success' => true,
            'data'    => $this->toArray($request),
            'message' => 'Profil berhasil diambil',
        ]);
    }

    // ─── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Samarkan nomor telepon — tampilkan hanya 4 digit terakhir.
     * +6281234567890  → +628****7890
     * 081234567890    → 081****7890
     * null            → null
     */
    private function maskPhoneNumber(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $length = strlen($phone);

        // Perlu minimal 8 karakter untuk bisa disamarkan dengan bermakna
        if ($length < 8) {
            return $phone;
        }

        // Tampilkan 4 karakter pertama + mask + 4 digit terakhir
        // PHP 8.5 pipe operator |> untuk transformasi berurutan
        return $phone
            |> (fn(string $n): string => substr($n, 0, 4))
            |> (fn(string $prefix): string => $prefix . '****' . substr($phone, -4));
    }
}

