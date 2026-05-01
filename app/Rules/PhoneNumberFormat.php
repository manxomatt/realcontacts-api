<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Custom rule validasi format nomor telepon untuk RealContacts.
 *
 * Format yang diterima:
 * - Internasional : +[kode negara][nomor]   contoh: +6281234567890
 * - Indonesia (62): 6281234567890
 * - Indonesia (0) : 081234567890
 *
 * Yang ditolak:
 * - Semua digit sama (00000000, 11111111 — nomor dummy tidak valid)
 * - Nomor yang diawali 0000
 * - Kurang dari 8 atau lebih dari 15 digit bersih
 */
final class PhoneNumberFormat implements ValidationRule
{
    // Panjang digit bersih (tanpa karakter + atau awalan non-digit)
    private const MIN_DIGITS = 8;
    private const MAX_DIGITS = 15;

    // Format yang diterima setelah normalisasi
    private const VALID_PATTERN = '/^(\+[1-9][0-9]{0,3}|0)[1-9][0-9]{6,13}$|^[1-9][0-9]{7,14}$/';

    // ─── Implementasi Rule ─────────────────────────────────────────────────────

    /**
     * Jalankan semua validasi secara berurutan menggunakan pipe operator PHP 8.5.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Normalisasi input terlebih dahulu — hapus spasi, strip, titik
        $normalized = self::normalize((string) $value);

        // Ambil digit bersih untuk cek panjang dan pola berulang
        // PHP 8.5 pipe operator |> untuk pipeline transformasi berurutan
        $digitsOnly = $normalized
            |> (fn(string $n): string => ltrim($n, '+'))
            |> (fn(string $n): string => preg_replace('/\D/', '', $n));

        // ── Cek 1: Panjang digit ───────────────────────────────────────────────
        $digitCount = strlen($digitsOnly);
        if ($digitCount < self::MIN_DIGITS || $digitCount > self::MAX_DIGITS) {
            $fail('Format nomor telepon tidak valid. Panjang nomor harus antara 8–15 digit.');
            return;
        }

        // ── Cek 2: Tolak nomor yang semua digitnya sama (0000, 1111, dsb.) ─────
        if (strlen(count_chars($digitsOnly, 3)) === 1) {
            $fail('Format nomor telepon tidak valid.');
            return;
        }

        // ── Cek 3: Tolak nomor yang diawali 0000 ──────────────────────────────
        if (str_starts_with($digitsOnly, '0000')) {
            $fail('Format nomor telepon tidak valid.');
            return;
        }

        // ── Cek 4: Validasi format umum (internasional / lokal Indonesia) ──────
        if (!preg_match(self::VALID_PATTERN, $normalized)) {
            $fail('Format nomor telepon tidak valid. Gunakan format 08xx, +62xx, atau 62xx.');
        }
    }

    // ─── Static Helper ────────────────────────────────────────────────────────

    /**
     * Normalisasi nomor telepon — dapat digunakan oleh Form Request lain
     * tanpa perlu menginstansiasi rule ini.
     *
     * Transformasi (pipe operator PHP 8.5):
     *   " +62 812-345 6789 " → "+6281234567890"
     *   "081234567890"       → "+6281234567890"
     *   "6281234567890"      → "+6281234567890"
     *   "+6281234567890"     → "+6281234567890"
     */
    public static function normalize(string $number): string
    {
        return $number
            // Hapus semua karakter kecuali digit dan '+'
            |> (fn(string $n): string => preg_replace('/[^\d+]/', '', trim($n)))
            // 08xx → +62xx
            |> (fn(string $n): string => str_starts_with($n, '08')
                ? '+62' . substr($n, 1)
                : $n)
            // 628xx tanpa + → +628xx
            |> (fn(string $n): string => str_starts_with($n, '628') && !str_starts_with($n, '+')
                ? '+' . $n
                : $n);
    }
}
