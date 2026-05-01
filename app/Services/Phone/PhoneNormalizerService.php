<?php

declare(strict_types=1);

namespace App\Services\Phone;

/**
 * PhoneNormalizerService
 *
 * Menangani semua normalisasi, parsing, dan masking format nomor telepon.
 * Pure logic — tidak ada dependency eksternal atau database call.
 */
final class PhoneNormalizerService
{
    // ─── Tabel prefix operator Indonesia ──────────────────────────────────────
    // Urutan penting: By.U (0851) harus sebelum Telkomsel agar array_first() cocok duluan.

    /** @var array<string, string[]> */
    private const OPERATOR_PREFIXES = [
        'By.U'      => ['0851'],
        'Telkomsel' => ['0811', '0812', '0813', '0821', '0822', '0823', '0852', '0853'],
        'Indosat'   => ['0814', '0815', '0816', '0855', '0856', '0857', '0858'],
        'XL'        => ['0817', '0818', '0819', '0859', '0877', '0878'],
        'Tri'       => ['0895', '0896', '0897', '0898', '0899'],
        'Smartfren' => ['0881', '0882', '0883', '0884', '0885', '0886', '0887', '0888', '0889'],
    ];

    // ─── Tabel kode negara ASEAN + umum ───────────────────────────────────────

    /** @var array<string, string> */
    private const COUNTRY_CODES = [
        '+62' => 'Indonesia',
        '+60' => 'Malaysia',
        '+65' => 'Singapore',
        '+63' => 'Philippines',
        '+66' => 'Thailand',
        '+84' => 'Vietnam',
        '+95' => 'Myanmar',
        '+855'=> 'Cambodia',
        '+856'=> 'Laos',
        '+673'=> 'Brunei',
        '+61' => 'Australia',
        '+1'  => 'United States',
        '+44' => 'United Kingdom',
    ];

    // Pola validasi format dasar nomor
    private const VALID_PATTERN = '/^(\+[1-9][0-9]{0,3}|0)[1-9][0-9]{6,13}$|^[1-9][0-9]{7,14}$/';

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Normalisasi semua format nomor ke +62xxx (atau +[kode] untuk internasional).
     *
     * Transformasi dengan pipe operator PHP 8.5:
     *   "  081 234-567 890 " → "+6281234567890"
     *   "081234567890"       → "+6281234567890"
     *   "6281234567890"      → "+6281234567890"
     *   "+6281234567890"     → "+6281234567890"
     *   "+44 20 7946 0958"   → "+442079460958"
     */
    public function normalize(string $phoneNumber): string
    {
        return $phoneNumber
            // 1. Hapus semua karakter kecuali digit, '+', kurung, strip, titik, spasi
            |> (fn(string $n): string => preg_replace('/[^\d+]/', '', trim($n)))
            // 2. 08xx → +62xx (format lokal Indonesia)
            |> (fn(string $n): string => str_starts_with($n, '08')
                ? '+62' . substr($n, 1)
                : $n)
            // 3. 628xx tanpa '+' → +628xx
            |> (fn(string $n): string => str_starts_with($n, '628') && !str_starts_with($n, '+')
                ? '+' . $n
                : $n);
    }

    /**
     * Deteksi nama operator seluler berdasarkan prefix 4 digit pertama.
     *
     * Menggunakan array_first() PHP 8.5 untuk mendapatkan operator pertama yang cocok.
     * Nomor harus dalam format lokal 08xx atau +628xx.
     *
     * Contoh:
     *   "+6281234567890" → "Telkomsel"
     *   "+6289512345678" → "Tri"
     *   "+4420791234567" → "Unknown"
     */
    public function detectOperator(string $phoneNumber): string
    {
        // Konversi ke format lokal 08xx untuk matching prefix
        $local = $this->toLocalFormat($this->normalize($phoneNumber));

        // Ambil 4 digit pertama sebagai prefix
        $prefix = substr($local, 0, 4);

        // Filter operator yang prefix-nya cocok, lalu ambil yang pertama
        // dengan array_first() PHP 8.5 — tidak menerima callback, pakai array_filter dulu
        $matched = array_first(
            array_keys(array_filter(
                self::OPERATOR_PREFIXES,
                fn(array $prefixes): bool => in_array($prefix, $prefixes, strict: true)
            ))
        );

        return $matched ?? 'Unknown';
    }

    /**
     * Deteksi negara asal nomor dari kode negara.
     *
     * Dicek dari kode terpanjang ke terpendek (3 digit → 2 digit → 1 digit)
     * untuk menghindari false positive (+1 vs +1xxx).
     *
     * Contoh:
     *   "+6281234567890"  → "Indonesia"
     *   "+60112345678"    → "Malaysia"
     *   "+442079460958"   → "United Kingdom"
     *   "+855123456789"   → "Cambodia"
     */
    public function detectCountry(string $phoneNumber): string
    {
        $normalized = $this->normalize($phoneNumber);

        // Jika tidak diawali '+', bukan format internasional yang bisa dideteksi
        if (!str_starts_with($normalized, '+')) {
            return 'International';
        }

        // Coba cocokkan dari kode terpanjang (3 digit) ke terpendek (1 digit)
        foreach ([4, 3, 2] as $length) {
            // Ambil +xx atau +xxx dari awal string (termasuk tanda '+')
            $code = substr($normalized, 0, $length);
            if (isset(self::COUNTRY_CODES[$code])) {
                return self::COUNTRY_CODES[$code];
            }
        }

        return 'International';
    }

    /**
     * Validasi format dasar nomor telepon.
     *
     * Cek: panjang digit 8–15, tidak semua digit sama,
     * tidak diawali 0000, dan cocok pola umum.
     */
    public function isValidFormat(string $phoneNumber): bool
    {
        $normalized = $this->normalize($phoneNumber);

        // Ambil digit bersih
        $digitsOnly = $normalized
            |> (fn(string $n): string => ltrim($n, '+'))
            |> (fn(string $n): string => preg_replace('/\D/', '', $n));

        $digitCount = strlen($digitsOnly);

        // Panjang digit 8–15
        if ($digitCount < 8 || $digitCount > 15) {
            return false;
        }

        // Tolak semua digit sama (0000000, 1111111, dsb.)
        if (strlen(count_chars($digitsOnly, 3)) === 1) {
            return false;
        }

        // Tolak awalan 0000
        if (str_starts_with($digitsOnly, '0000')) {
            return false;
        }

        return (bool) preg_match(self::VALID_PATTERN, $normalized);
    }

    /**
     * Sembunyikan digit tengah nomor untuk keperluan privasi.
     *
     * Format output: 4 digit pertama + **** + 4 digit terakhir
     *
     * Contoh:
     *   "+6281234567890" → "+628****7890"
     *   "+62811223344"   → "+628****3344"
     */
    public function maskNumber(string $phoneNumber): string
    {
        $normalized = $this->normalize($phoneNumber);

        $length = strlen($normalized);

        // Jika nomor terlalu pendek untuk di-mask, kembalikan apa adanya
        if ($length <= 8) {
            return $normalized;
        }

        // Tampilkan 4 karakter pertama + **** + 4 karakter terakhir
        $head = substr($normalized, 0, 4);
        $tail = substr($normalized, -4);

        return $head . '****' . $tail;
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Konversi format internasional +628xx ke format lokal 08xx.
     * Digunakan secara internal untuk matching prefix operator.
     */
    private function toLocalFormat(string $normalized): string
    {
        if (str_starts_with($normalized, '+62')) {
            return '0' . substr($normalized, 3);
        }

        return $normalized;
    }
}
