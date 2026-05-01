<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpamReport extends Model
{
    /** @use HasFactory<\Database\Factories\SpamReportFactory> */
    use HasFactory;

    // Kolom yang boleh diisi secara massal
    protected $fillable = [
        'phone_number_id',
        'user_id',
        'report_type',
        'description',
        'upvotes',
        'downvotes',
        'status',
    ];

    // Konversi tipe data kolom secara otomatis
    protected $casts = [
        'upvotes'   => 'integer',
        'downvotes' => 'integer',
    ];

    // Semua kategori laporan yang valid
    const REPORT_TYPES = [
        'spam',
        'telemarketing',
        'robo_call',
        'harassment',
        'fraud_bank',
        'fraud_prize',
        'debt_collector',
        'survey',
        'unknown_spam',
    ];

    // Status moderasi laporan yang valid
    const STATUSES = ['pending', 'verified', 'rejected'];

    // ──────────────────────────────────────────────
    // Relasi
    // ──────────────────────────────────────────────

    // Laporan spam dimiliki oleh satu nomor telepon
    public function phoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    // Laporan spam dibuat oleh satu user (opsional, null = anonim)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    // Filter hanya laporan yang sudah terverifikasi oleh moderator
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->where('status', 'verified');
    }

    // Filter laporan yang masih menunggu moderasi
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', 'pending');
    }
}


