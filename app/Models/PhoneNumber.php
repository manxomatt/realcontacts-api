<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

// PHP 8.5: #[Fillable] dan #[Hidden] sebagai class-level attribute Laravel 13
#[Fillable([
    'phone_number',
    'normalized_number',
    'country_code',
    'owner_name',
    'operator_name',
    'spam_score',
    'category',
    'total_reports',
    'is_verified',
    'last_updated',
])]
#[Hidden(['normalized_number'])]
class PhoneNumber extends Model
{
    /** @use HasFactory<\Database\Factories\PhoneNumberFactory> */
    use HasFactory;

    // Konversi tipe data kolom — #[Cast] belum tersedia di Laravel 13
    protected $casts = [
        'spam_score'    => 'integer',
        'total_reports' => 'integer',
        'is_verified'   => 'boolean',
        'last_updated'  => 'datetime',
    ];

    // ──────────────────────────────────────────────
    // Relasi
    // ──────────────────────────────────────────────

    // Satu nomor telepon bisa memiliki banyak laporan spam
    public function spamReports(): HasMany
    {
        return $this->hasMany(SpamReport::class);
    }

    // Satu nomor telepon bisa memiliki satu profil bisnis
    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    // ──────────────────────────────────────────────
    // Accessor
    // ──────────────────────────────────────────────

    // Mengembalikan level bahaya spam berdasarkan skor
    protected function spamLevel(): Attribute
    {
        return Attribute::make(
            get: fn (): string => match (true) {
                $this->spam_score > 70  => 'danger',
                $this->spam_score >= 30 => 'warning',
                default                 => 'safe',
            }
        );
    }

    // ──────────────────────────────────────────────
    // Scopes — Laravel 13 native #[Scope] attribute
    // ──────────────────────────────────────────────

    // Filter hanya nomor yang sudah terverifikasi
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->where('is_verified', true);
    }

    // Filter nomor berdasarkan kategori tertentu
    #[Scope]
    protected function byCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    // ──────────────────────────────────────────────
    // Methods
    // ──────────────────────────────────────────────

    // PHP 8.5: #[\NoDiscard] — wajib gunakan nilai kembalian, jangan abaikan
    // Normalisasi format nomor telepon ke format internasional +62
    // Menggunakan pipe operator |> (PHP 8.5) untuk pipeline transformasi
    #[\NoDiscard("Gunakan nilai kembalian normalized_number untuk disimpan ke database")]
    public function normalizePhoneNumber(string $number): string
    {
        // PHP 8.5 pipe operator |> — arrow function di sisi kanan wajib dibungkus ()
        return $number
            |> (fn(string $n): string => preg_replace('/[^\d+]/', '', $n))
            |> (fn(string $n): string => str_starts_with($n, '0')
                ? '+62' . substr($n, 1)
                : $n)
            |> (fn(string $n): string => str_starts_with($n, '62')
                ? '+' . $n
                : $n)
            |> (fn(string $n): string => str_starts_with($n, '+')
                ? $n
                : '+62' . $n);
    }

    // PHP 8.5: array_first() built-in — ambil elemen pertama array tanpa loop
    // Mengembalikan jenis laporan spam yang paling dominan pada nomor ini
    #[\NoDiscard("Digunakan untuk menentukan label kategori utama pada PhoneNumberResource")]
    public function getDominantSpamCategory(): ?string
    {
        // Ambil jenis laporan diurutkan dari yang terbanyak
        $reportTypes = $this->spamReports()
            ->selectRaw('report_type, COUNT(*) as total')
            ->groupBy('report_type')
            ->orderByDesc('total')
            ->pluck('report_type')
            ->toArray();

        // PHP 8.5 built-in array_first() — lebih ekspresif dari $arr[0] ?? null
        return array_first($reportTypes);
    }

    // Immutable spam score update — karena clone with belum masuk PHP 8.5,
    // gunakan pola clone + property assignment sebagai alternatif idiomatik
    public function withSpamScore(int $score): static
    {
        // Kloning model tanpa menyentuh instance asli (immutable update)
        $clone             = clone $this;
        $clone->spam_score = $score;
        $clone->exists     = false; // tandai sebagai record baru, belum tersimpan

        return $clone;
    }
}

