<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpamReportResource extends JsonResource
{
    public static $wrap = null;

    // Peta enum report_type ke label human-readable Bahasa Indonesia
    private const CATEGORY_LABELS = [
        'spam'           => 'Spam Umum',
        'telemarketing'  => 'Telemarketing / Promosi',
        'robo_call'      => 'Robocall / Otomatis',
        'harassment'     => 'Pelecehan / Ancaman',
        'fraud_bank'     => 'Penipuan Bank',
        'debt_collector' => 'Penagih Utang',
        'unknown_spam'   => 'Spam Tidak Dikenal',
    ];

    /**
     * Transformasi model SpamReport ke array response.
     * Field sensitif (phone_number_id, user_id, raw timestamps) disembunyikan.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // ─── Kategori ─────────────────────────────────────────────────────

            // Nilai enum mentah untuk kebutuhan programatik (filter, query)
            'category' => $this->report_type,

            // Label human-readable — lebih informatif untuk tampilan UI
            'category_label' => self::CATEGORY_LABELS[$this->report_type]
                ?? 'Spam Tidak Dikenal',

            // ─── Detail Laporan ────────────────────────────────────────────────

            // Keterangan opsional dari pelapor — tampilkan null jika kosong
            'description' => $this->whenNotNull($this->description),

            // ─── Voting & Kredibilitas ─────────────────────────────────────────

            'upvotes'   => $this->upvotes,
            'downvotes' => $this->downvotes,

            // Skor kredibilitas laporan: upvotes / (upvotes + downvotes) * 100
            // Ditampilkan null jika belum ada satu pun vote (hindari division by zero)
            'credibility_score' => $this->calculateCredibilityScore(),

            // ─── Status Moderasi ───────────────────────────────────────────────

            // Status: 'pending' | 'verified' | 'rejected'
            'status' => $this->status,

            // ─── Waktu ────────────────────────────────────────────────────────

            // Format relatif — lebih ramah bagi pengguna akhir
            'reported_at' => $this->created_at->diffForHumans(),

            // ─── Pelapor (privasi dijaga) ──────────────────────────────────────

            // whenLoaded() mencegah N+1 query — hanya muncul jika
            // ->with('user') dipanggil di Controller
            // TIDAK menampilkan nama, email, atau nomor telepon reporter
            'reporter' => $this->whenLoaded('user', function () {
                // Jika user null (anonim), kembalikan representasi anonim
                if ($this->user === null) {
                    return ['type' => 'anonymous', 'badge_level' => null];
                }

                // Hanya tampilkan badge_level — identitas tetap tersembunyi
                return [
                    'type'        => 'user',
                    'badge_level' => $this->user->badge_level,
                ];
            }),
        ];
    }

    // ─── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Hitung skor kredibilitas laporan (0–100).
     * Kembalikan null jika total votes masih 0 untuk menghindari false metric.
     */
    private function calculateCredibilityScore(): ?int
    {
        $total = $this->upvotes + $this->downvotes;

        if ($total === 0) {
            return null;
        }

        // PHP 8.5 integer division via intdiv() — hasil selalu integer bersih
        return intdiv($this->upvotes * 100, $total);
    }
}


