<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\SmsProviderInterface;
use App\Services\Phone\PhoneNormalizerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * OtpService
 *
 * Mengelola seluruh siklus hidup OTP:
 * generate → send → verify, dengan proteksi brute-force
 * berupa pembatasan attempt dan blacklist sementara.
 *
 * Redis key pattern yang digunakan:
 *   otp:{normalized}          → kode OTP, TTL 5 menit
 *   otp_attempts:{normalized} → counter percobaan, TTL 15 menit
 *   otp_blacklist:{normalized} → flag blacklist, TTL 15 menit
 */
final class OtpService
{
    // ─── Konfigurasi ──────────────────────────────────────────────────────────

    /** Panjang kode OTP (digit) */
    private const OTP_LENGTH = 6;

    /** TTL OTP di Redis (detik) */
    private const TTL_OTP = 300;           // 5 menit

    /** TTL attempt counter dan blacklist (detik) */
    private const TTL_ATTEMPTS = 900;      // 15 menit

    /** Maksimal percobaan verify sebelum blacklist */
    private const MAX_ATTEMPTS = 3;

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly PhoneNormalizerService $normalizer,
        private readonly SmsProviderInterface   $smsProvider,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Generate OTP 6 digit dan simpan ke Redis.
     *
     * Alur:
     *   1. Normalisasi nomor via PhoneNormalizerService (pipe |>)
     *   2. Generate kode acak 6 digit (cryptographically safe)
     *   3. Simpan ke Redis dengan TTL 5 menit
     *   4. Reset attempt counter (TTL 15 menit)
     *
     * @return string Kode OTP 6 digit
     */
    public function generate(string $phoneNumber): string
    {
        // Normalisasi nomor — PHP 8.5 pipe operator
        $normalized = $phoneNumber
            |> (fn(string $n): string => $this->normalizer->normalize($n));

        // Generate kode OTP — pakai random_int agar kriptografis aman
        $otpCode = str_pad(
            string: (string) random_int(0, 999_999),
            length: self::OTP_LENGTH,
            pad_string: '0',
            pad_type: STR_PAD_LEFT
        );

        // Simpan OTP ke Redis dengan TTL 5 menit
        Redis::setex(
            $this->keyOtp($normalized),
            self::TTL_OTP,
            $otpCode
        );

        // Inisialisasi / reset attempt counter dengan TTL 15 menit
        Redis::setex(
            $this->keyAttempts($normalized),
            self::TTL_ATTEMPTS,
            '0'
        );

        return $otpCode;
    }

    /**
     * Kirim OTP ke nomor tujuan via SMS provider.
     *
     * OTP tidak dilog — hanya metadata pengiriman yang dicatat
     * untuk mencegah kebocoran kode di log file.
     *
     * @param  string $phoneNumber Nomor tujuan
     * @param  string $otpCode     Kode OTP yang sudah digenerate
     * @return bool                true jika provider menerima pesan
     */
    public function send(string $phoneNumber, string $otpCode): bool
    {
        $normalized = $phoneNumber
            |> (fn(string $n): string => $this->normalizer->normalize($n));

        // Pesan SMS standar — tidak mengandung format yang bisa di-phishing
        $message = "Kode verifikasi RealContacts Anda: {$otpCode}. "
                 . 'Berlaku 5 menit. Jangan bagikan ke siapapun.';

        $success = $this->smsProvider->send($normalized, $message);

        // Log metadata pengiriman — JANGAN log isi otpCode
        Log::info('OTP dikirim', [
            'phone'   => $this->normalizer->maskNumber($normalized),
            'success' => $success,
        ]);

        return $success;
    }

    /**
     * Verifikasi kode OTP yang diinput user.
     *
     * Proteksi keamanan:
     *   - hash_equals() mencegah timing attack (constant-time comparison)
     *   - Setiap gagal → increment attempt counter
     *   - Attempt >= MAX_ATTEMPTS → blacklist 15 menit
     *   - OTP valid → langsung hapus dari Redis (one-time use)
     *
     * @param  string $phoneNumber Nomor yang melakukan verifikasi
     * @param  string $otpCode     Kode yang diinput user
     * @return bool                true jika OTP cocok dan belum expired
     */
    public function verify(string $phoneNumber, string $otpCode): bool
    {
        $normalized = $phoneNumber
            |> (fn(string $n): string => $this->normalizer->normalize($n));

        // Ambil OTP dari Redis
        $stored = Redis::get($this->keyOtp($normalized));

        // OTP tidak ada atau sudah expired
        if ($stored === null || $stored === false) {
            return false;
        }

        // Bandingkan dengan hash_equals — constant-time, tahan timing attack
        $isValid = hash_equals((string) $stored, $otpCode);

        if ($isValid) {
            // OTP valid — hapus langsung agar tidak bisa dipakai ulang
            Redis::del($this->keyOtp($normalized));
            Redis::del($this->keyAttempts($normalized));

            return true;
        }

        // OTP salah — catat attempt
        $this->recordFailedAttempt($normalized);

        return false;
    }

    /**
     * Cek apakah nomor sedang dalam masa blacklist.
     *
     * Blacklist aktif jika key "otp_blacklist:{normalized}" ada di Redis.
     * Key tersebut di-set oleh recordFailedAttempt() setelah attempt >= MAX.
     */
    public function isBlacklisted(string $phoneNumber): bool
    {
        $normalized = $phoneNumber
            |> (fn(string $n): string => $this->normalizer->normalize($n));

        $value = Redis::get($this->keyBlacklist($normalized));

        return $value !== null && $value !== false;
    }

    /**
     * Sisa percobaan verify yang dimiliki user.
     *
     * Jika counter tidak ada di Redis (belum pernah salah), return MAX_ATTEMPTS.
     * Sisa = MAX_ATTEMPTS - jumlah_gagal, minimum 0.
     *
     * @return int 0–3
     */
    public function getRemainingAttempts(string $phoneNumber): int
    {
        $normalized = $phoneNumber
            |> (fn(string $n): string => $this->normalizer->normalize($n));

        $attempts = (int) (Redis::get($this->keyAttempts($normalized)) ?? 0);

        return max(0, self::MAX_ATTEMPTS - $attempts);
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Catat satu percobaan verify yang gagal.
     *
     * Jika total kegagalan mencapai MAX_ATTEMPTS, set key blacklist
     * dengan TTL 15 menit dan hapus OTP dari Redis.
     */
    private function recordFailedAttempt(string $normalizedPhone): void
    {
        $attemptsKey = $this->keyAttempts($normalizedPhone);

        // Increment — jika key belum ada, Redis akan buat dengan nilai 1
        $attempts = (int) Redis::incr($attemptsKey);

        // Pastikan TTL counter masih aktif (INCR tidak mengubah TTL yang ada)
        // Hanya set TTL jika key baru (TTL = -1 berarti tidak ada expire)
        if (Redis::ttl($attemptsKey) === -1) {
            Redis::expire($attemptsKey, self::TTL_ATTEMPTS);
        }

        // Blacklist jika sudah mencapai batas maksimum
        if ($attempts >= self::MAX_ATTEMPTS) {
            Redis::setex(
                $this->keyBlacklist($normalizedPhone),
                self::TTL_ATTEMPTS,
                '1'
            );

            // Hapus OTP — tidak perlu disimpan lagi
            Redis::del($this->keyOtp($normalizedPhone));

            Log::warning('Nomor di-blacklist karena terlalu banyak percobaan OTP', [
                'phone'    => $this->normalizer->maskNumber($normalizedPhone),
                'attempts' => $attempts,
            ]);
        }
    }

    // ─── Key Builders ─────────────────────────────────────────────────────────

    /** Key Redis untuk kode OTP */
    private function keyOtp(string $normalized): string
    {
        return "otp:{$normalized}";
    }

    /** Key Redis untuk counter percobaan */
    private function keyAttempts(string $normalized): string
    {
        return "otp_attempts:{$normalized}";
    }

    /** Key Redis untuk blacklist */
    private function keyBlacklist(string $normalized): string
    {
        return "otp_blacklist:{$normalized}";
    }
}
