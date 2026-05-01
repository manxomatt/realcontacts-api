<?php

declare(strict_types=1);

namespace App\Services\Block;

use App\Models\BlockedNumber;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Cache\PhoneCacheService;
use App\Services\Phone\PhoneNormalizerService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * CallBlockerService
 *
 * Mengelola daftar blokir panggilan milik setiap user.
 *
 * Redis key patterns:
 *   blocklist:{user_id}         → Redis Set berisi normalized_number yang diblokir
 *                                  TTL: 24 jam
 *
 * Alur isBlocked():
 *   SISMEMBER cache → jika set ada, return hasil
 *   Cache miss      → syncBlocklistCache() lalu cek DB
 */
final class CallBlockerService
{
    /** Batas maksimal nomor yang bisa diblokir satu user */
    private const MAX_BLOCKED_NUMBERS = 500;

    /** TTL cache blocklist user (detik) */
    private const TTL_BLOCKLIST = 86_400;   // 24 jam

    /** Prefix key Redis blocklist */
    private const KEY_PREFIX = 'blocklist:';

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly PhoneNormalizerService $normalizer,
        private readonly PhoneCacheService      $cacheService,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Blokir sebuah nomor telepon untuk user tertentu.
     *
     * Alur:
     *   1. Normalisasi nomor
     *   2. Cari atau buat entry PhoneNumber
     *   3. Buat BlockedNumber
     *   4. Tambahkan ke Redis Set blocklist user
     *
     * @param  User                 $user           User yang memblokir
     * @param  array<string, mixed> $validatedData  Data dari StoreBlockedNumberRequest
     * @return BlockedNumber                         Entry yang baru dibuat
     */
    public function block(User $user, array $validatedData): BlockedNumber
    {
        // phone_number sudah dinormalisasi oleh StoreBlockedNumberRequest
        $normalized = (string) $validatedData['phone_number'];

        // Cari atau buat PhoneNumber entry
        $phoneModel = PhoneNumber::firstOrCreate(
            ['normalized_number' => $normalized],
            [
                'phone_number'  => $normalized,
                'country_code'  => substr($normalized, 0, 3),
                'spam_score'    => 0,
                'total_reports' => 0,
                'category'      => 'unknown',
                'is_verified'   => false,
                'last_updated'  => now(),
            ]
        );

        // Buat entry blocklist
        $blocked = DB::transaction(function () use ($user, $phoneModel, $validatedData): BlockedNumber {
            return BlockedNumber::create([
                'user_id'         => $user->id,
                'phone_number_id' => $phoneModel->id,
                'reason'          => $validatedData['reason'] ?? null,
                'block_type'      => $validatedData['block_type'] ?? 'manual',
                'category'        => $validatedData['category']  ?? null,
                'schedule'        => $validatedData['schedule']  ?? null,
            ]);
        });

        // Tambahkan ke Redis Set — SADD atomic
        Redis::sadd($this->cacheKey($user->id), $normalized);

        // Pastikan TTL tetap aktif setelah SADD
        $this->ensureTtl($user->id);

        // Load relasi agar tidak lazy-load di controller
        $blocked->load('phoneNumber');

        return $blocked;
    }

    /**
     * Hapus blokir untuk sebuah nomor telepon.
     *
     * @param  User   $user         User pemilik blocklist
     * @param  string $phoneNumber  Nomor yang akan di-unblock (format apapun)
     * @return bool                 true jika berhasil dihapus
     */
    public function unblock(User $user, string $phoneNumber): bool
    {
        $normalized = $this->normalizer->normalize($phoneNumber);

        $deleted = DB::transaction(function () use ($user, $normalized): int {
            return BlockedNumber::where('user_id', $user->id)
                ->whereHas('phoneNumber', fn($q) => $q->where('normalized_number', $normalized))
                ->delete();
        });

        if ($deleted > 0) {
            // Hapus dari Redis Set
            Redis::srem($this->cacheKey($user->id), $normalized);

            return true;
        }

        return false;
    }

    /**
     * Cek apakah sebuah nomor diblokir oleh user.
     *
     * Strategi: cek Redis Set terlebih dahulu (O(1)).
     * Jika cache set belum ada (SCARD = 0 atau key tidak ada),
     * lakukan syncBlocklistCache() terlebih dahulu lalu cek ulang.
     *
     * @param  User   $user
     * @param  string $phoneNumber  Nomor dalam format apapun
     * @return bool
     */
    public function isBlocked(User $user, string $phoneNumber): bool
    {
        $normalized = $this->normalizer->normalize($phoneNumber);
        $key        = $this->cacheKey($user->id);

        // Cek apakah Redis Set sudah ada
        $keyExists = Redis::exists($key);

        if (! $keyExists) {
            // Cache miss — sync dari DB lalu cek lagi
            $this->syncBlocklistCache($user);
        }

        // SISMEMBER — O(1)
        return (bool) Redis::sismember($key, $normalized);
    }

    /**
     * Ambil daftar blokir user secara paginated.
     *
     * Eager load phoneNumber untuk menampilkan info nomor
     * tanpa query N+1.
     *
     * @param  User $user
     * @param  int  $perPage
     * @return LengthAwarePaginator
     */
    public function getUserBlocklist(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return BlockedNumber::where('user_id', $user->id)
            ->with(['phoneNumber:id,phone_number,normalized_number,spam_score,owner_name,operator_name'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Rebuild cache blocklist dari database.
     *
     * Menggunakan Redis pipeline untuk efisiensi — semua SADD dan EXPIRE
     * dikirim dalam satu round-trip.
     *
     * Dipanggil saat:
     *   - isBlocked() menemukan cache tidak ada
     *   - Setelah importBlocklist() massal
     *
     * @param  User $user
     */
    public function syncBlocklistCache(User $user): void
    {
        $key = $this->cacheKey($user->id);

        // Ambil semua normalized_number yang diblokir user ini
        $blockedNumbers = BlockedNumber::where('user_id', $user->id)
            ->with('phoneNumber:id,normalized_number')
            ->get()
            ->pluck('phoneNumber.normalized_number')
            ->filter()              // Hapus null (jika phoneNumber sudah dihapus)
            ->values()
            ->toArray();

        // Hapus set lama dan rebuild dengan pipeline
        Redis::pipeline(function ($pipe) use ($key, $blockedNumbers): void {
            $pipe->del($key);

            if (!empty($blockedNumbers)) {
                // SADD menerima variadic args — sebar array
                $pipe->sadd($key, ...$blockedNumbers);
            }

            $pipe->expire($key, self::TTL_BLOCKLIST);
        });
    }

    /**
     * Import banyak nomor ke blocklist sekaligus.
     *
     * Validasi:
     *   - Skip nomor yang sudah diblokir (duplikat)
     *   - Stop jika total melebihi MAX_BLOCKED_NUMBERS
     *
     * @param  User     $user
     * @param  string[] $phoneNumbers  Array nomor (belum dinormalisasi)
     * @return array{imported: int, skipped: int, failed: int}
     */
    public function importBlocklist(User $user, array $phoneNumbers): array
    {
        $imported = 0;
        $skipped  = 0;
        $failed   = 0;

        // Hitung jumlah blocklist saat ini
        $currentCount = BlockedNumber::where('user_id', $user->id)->count();

        // ID PhoneNumber yang sudah diblokir user — untuk cek duplikat efisien
        $existingIds = BlockedNumber::where('user_id', $user->id)
            ->pluck('phone_number_id')
            ->toArray();

        foreach ($phoneNumbers as $raw) {
            // Cek batas maksimum
            if (($currentCount + $imported) >= self::MAX_BLOCKED_NUMBERS) {
                // Sisa nomor dianggap failed karena sudah penuh
                $failed += count($phoneNumbers) - $imported - $skipped - $failed;
                break;
            }

            try {
                $normalized = $this->normalizer->normalize((string) $raw);

                // Cari atau buat PhoneNumber
                $phoneModel = PhoneNumber::firstOrCreate(
                    ['normalized_number' => $normalized],
                    [
                        'phone_number'  => $normalized,
                        'country_code'  => substr($normalized, 0, 3),
                        'spam_score'    => 0,
                        'total_reports' => 0,
                        'category'      => 'unknown',
                        'is_verified'   => false,
                        'last_updated'  => now(),
                    ]
                );

                // Skip jika sudah diblokir
                if (in_array($phoneModel->id, $existingIds, strict: true)) {
                    $skipped++;
                    continue;
                }

                BlockedNumber::create([
                    'user_id'         => $user->id,
                    'phone_number_id' => $phoneModel->id,
                    'block_type'      => 'manual',
                ]);

                $existingIds[] = $phoneModel->id;
                $imported++;

            } catch (\Throwable) {
                $failed++;
            }
        }

        // Rebuild cache setelah import massal
        if ($imported > 0) {
            $this->syncBlocklistCache($user);
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'failed'   => $failed,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Bangun Redis key untuk blocklist user.
     */
    private function cacheKey(int $userId): string
    {
        return self::KEY_PREFIX . $userId;
    }

    /**
     * Pastikan TTL Redis Set masih aktif setelah operasi SADD.
     *
     * SADD tidak mengubah TTL yang sudah ada. Jika key baru dibuat
     * (TTL = -1), set TTL ke 24 jam.
     */
    private function ensureTtl(int $userId): void
    {
        $key = $this->cacheKey($userId);
        $ttl = Redis::ttl($key);

        // TTL -1 = key ada tapi tidak ada expire → set expire
        if ($ttl === -1) {
            Redis::expire($key, self::TTL_BLOCKLIST);
        }
    }
}
