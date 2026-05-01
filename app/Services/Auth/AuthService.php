<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Exceptions\OtpBlacklistedException;
use App\Exceptions\OtpExpiredException;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\OtpService;
use App\Services\Contact\ContactTagService;
use App\Services\User\UserService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * AuthService
 *
 * Menangani seluruh alur autentikasi RealContacts:
 * register → verifyAndLogin → logout → requestNewOtp.
 *
 * Desain:
 *   - AuthService tidak menyentuh Redis langsung untuk OTP;
 *     semua delegasi ke OtpService.
 *   - Token Sanctum dibuat di sini (bukan di Controller) agar
 *     mudah di-mock saat testing.
 */
final class AuthService
{
    // ─── Status akun user ─────────────────────────────────────────────────────

    private const STATUS_UNVERIFIED = 'unverified';
    private const STATUS_ACTIVE     = 'active';

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly OtpService         $otpService,
        private readonly UserService       $userService,
        private readonly ContactTagService $contactTagService,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Registrasi user baru dan kirim OTP verifikasi.
     *
     * Alur:
     *   1. Cek apakah nomor sudah terdaftar (via UserService)
     *   2. Buat user baru dengan status 'unverified'
     *   3. Generate + kirim OTP
     *
     * @param  array<string, mixed> $validatedData  Data tervalidasi dari RegisterRequest
     * @return array{user: User, otp_sent: bool}
     * @throws \RuntimeException jika nomor sudah terdaftar
     */
    public function register(array $validatedData): array
    {
        // Normalisasi sudah dilakukan di RegisterRequest::prepareForValidation()
        // phone_number di sini sudah dalam format +62xxx
        $phoneNumber = (string) $validatedData['phone_number'];

        // Cek duplikat — UserService melempar exception jika sudah ada
        $this->userService->ensurePhoneNotRegistered($phoneNumber);

        // Buat user dalam satu transaksi
        $user = DB::transaction(function () use ($validatedData): User {
            $user = $this->userService->create([
                'name'         => $validatedData['name'],
                'phone_number' => $validatedData['phone_number'],
                'country_code' => $validatedData['country_code'] ?? '+62',
                'status'       => self::STATUS_UNVERIFIED,
                // Email opsional — RealContacts berbasis nomor HP
                'email'        => $validatedData['email'] ?? $this->generatePlaceholderEmail($validatedData['phone_number']),
                'password'     => '', // Tidak dipakai — auth via OTP
            ]);

            // Buat default system tags untuk user baru dalam transaksi yang sama
            $this->contactTagService->createDefaultTagsForUser($user);

            return $user;
        });

        // Generate dan kirim OTP
        $otpCode = $this->otpService->generate($phoneNumber);
        $otpSent = $this->otpService->send($phoneNumber, $otpCode);

        return [
            'user'     => $user,
            'otp_sent' => $otpSent,
        ];
    }

    /**
     * Verifikasi OTP dan buat Sanctum token untuk login.
     *
     * Alur:
     *   1. Verifikasi OTP via OtpService
     *   2. Update status user → 'active' + catat last_login_at
     *   3. Buat Sanctum token dengan nama perangkat
     *
     * @param  array<string, mixed> $validatedData  Data dari VerifyOtpRequest
     * @return array{user: UserResource, token: string, token_type: string}
     * @throws OtpExpiredException    OTP tidak ditemukan atau sudah expired
     * @throws AuthenticationException OTP salah
     */
    public function verifyAndLogin(array $validatedData): array
    {
        $phoneNumber = (string) $validatedData['phone_number'];
        $otpCode     = (string) $validatedData['otp_code'];
        $deviceName  = (string) ($validatedData['device_name'] ?? 'default');

        // Ambil user — harus ada karena sudah divalidasi di VerifyOtpRequest
        $user = User::where('phone_number', $phoneNumber)->firstOrFail();

        // Verifikasi OTP via OtpService (hash_equals, anti-timing attack)
        $isValid = $this->otpService->verify($phoneNumber, $otpCode);

        if (! $isValid) {
            // Cek apakah OTP masih ada di Redis atau sudah expired
            // OtpService::verify() mengembalikan false untuk keduanya —
            // bedakan dengan cek remaining attempts
            $remaining = $this->otpService->getRemainingAttempts($phoneNumber);

            if ($remaining === 0 && $this->otpService->isBlacklisted($phoneNumber)) {
                throw new OtpBlacklistedException();
            }

            throw new AuthenticationException(
                'Kode OTP tidak valid. Sisa percobaan: ' . $remaining
            );
        }

        // OTP valid — aktifkan akun dan catat waktu login
        $user->update([
            'status'        => self::STATUS_ACTIVE,
            'last_login_at' => now(),
        ]);

        // Buat Sanctum token
        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user'       => new UserResource($user),
            'token'      => $token,
            'token_type' => 'Bearer',
        ];
    }

    /**
     * Logout — revoke token aktif yang sedang digunakan.
     *
     * Menggunakan currentAccessToken() yang merujuk ke token
     * yang dikirim di request header Authorization.
     */
    public function logout(User $user): bool
    {
        $user->currentAccessToken()->delete();

        return true;
    }

    /**
     * Minta OTP baru (resend).
     *
     * Validasi:
     *   1. Nomor harus terdaftar di database
     *   2. Nomor tidak boleh sedang dalam blacklist OTP
     *
     * @param  string $phoneNumber  Nomor dalam format +62xxx (sudah dinormalisasi)
     * @return array{sent: bool, retry_after: int}
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException jika nomor tidak terdaftar
     * @throws OtpBlacklistedException jika sedang di-blacklist
     */
    public function requestNewOtp(string $phoneNumber): array
    {
        // Pastikan nomor terdaftar — lempar 404 jika tidak ada
        User::where('phone_number', $phoneNumber)->firstOrFail();

        // Cek blacklist sebelum generate OTP baru
        if ($this->otpService->isBlacklisted($phoneNumber)) {
            // Ambil TTL blacklist dari Redis untuk retry_after yang akurat
            $retryAfter = $this->resolveBlacklistTtl($phoneNumber);

            throw new OtpBlacklistedException(
                message: "Terlalu banyak percobaan. Coba lagi dalam {$this->formatSeconds($retryAfter)}.",
                retryAfter: $retryAfter,
            );
        }

        // Generate OTP baru dan kirim
        $otpCode = $this->otpService->generate($phoneNumber);
        $sent    = $this->otpService->send($phoneNumber, $otpCode);

        return [
            'sent'        => $sent,
            'retry_after' => 300,   // TTL OTP = 5 menit
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Buat email placeholder dari nomor telepon untuk memenuhi kolom UNIQUE email.
     *
     * Format: phone+62xxx@realcontacts.internal
     * Domain .internal tidak akan pernah menerima email nyata.
     */
    private function generatePlaceholderEmail(string $phoneNumber): string
    {
        // Bersihkan karakter non-alfanumerik dari nomor
        $clean = preg_replace('/[^0-9]/', '', $phoneNumber);

        return "phone{$clean}@realcontacts.internal";
    }

    /**
     * Ambil TTL sisa blacklist dari Redis.
     * Fallback 900 detik (15 menit) jika key tidak ditemukan.
     */
    private function resolveBlacklistTtl(string $phoneNumber): int
    {
        $key = "otp_blacklist:{$phoneNumber}";
        $ttl = Redis::ttl($key);

        // TTL -1 = tidak ada expire, -2 = key tidak ada
        return $ttl > 0 ? $ttl : 900;
    }

    /**
     * Format detik menjadi string yang ramah pengguna.
     * Contoh: 900 → "15 menit", 45 → "45 detik"
     */
    private function formatSeconds(int $seconds): string
    {
        if ($seconds >= 60) {
            $minutes = (int) ceil($seconds / 60);
            return "{$minutes} menit";
        }

        return "{$seconds} detik";
    }
}
