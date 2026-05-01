<?php

declare(strict_types=1);

namespace App\Services\Cache;

use App\Services\Phone\PhoneNormalizerService;
use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * PhoneCacheService
 *
 * Mengelola semua operasi Redis cache untuk data nomor telepon.
 * TTL dinamis berdasarkan skor spam — nomor aktif dilaporkan
 * lebih sering diperbarui (TTL pendek), nomor aman dicache lama.
 */
final class PhoneCacheService
{
    // ─── Konfigurasi TTL (detik) ───────────────────────────────────────────────

    /** Nomor spam aktif (score >= 70) — berubah cepat */
    private const TTL_SPAM    = 3_600;       // 1 jam

    /** Nomor perlu perhatian (score 30–69) */
    private const TTL_WARNING = 21_600;      // 6 jam

    /** Nomor aman (score < 30) */
    private const TTL_SAFE    = 86_400;      // 24 jam

    /** Nomor bisnis terverifikasi — stabil */
    private const TTL_BUSINESS = 172_800;    // 48 jam

    // ─── Key patterns Redis ────────────────────────────────────────────────────

    /** Prefix key data nomor telepon */
    private const KEY_PREFIX = 'phone:';

    /** Counter hit/miss untuk statistik */
    private const KEY_HIT_COUNTER  = 'phone_cache:hits';
    private const KEY_MISS_COUNTER = 'phone_cache:misses';

    public function __construct(
        private readonly PhoneNormalizerService $normalizer
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Ambil data nomor dari cache.
     *
     * Key pattern: "phone:{normalized_number}"
     * Return null jika tidak ditemukan (cache miss).
     *
     * @return array<string, mixed>|null
     */
    public function get(string $phoneNumber): ?array
    {
        $key  = $this->buildKey($phoneNumber);
        $data = Redis::get($key);

        if ($data === null || $data === false) {
            // Catat cache miss untuk statistik
            Redis::incr(self::KEY_MISS_COUNTER);
            return null;
        }

        // Catat cache hit untuk statistik
        Redis::incr(self::KEY_HIT_COUNTER);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($data, associative: true);

        return $decoded;
    }

    /**
     * Simpan data nomor ke cache dengan TTL dinamis berdasarkan skor spam.
     *
     * TTL ditentukan dari field 'spam_score' dan 'is_business_verified' pada $data.
     * Metadata cached_at dan ttl_hours ditambahkan ke payload.
     */
    public function put(string $phoneNumber, array $data): bool
    {
        $key = $this->buildKey($phoneNumber);
        $ttl = $this->resolveTtl($data);

        // Tambahkan metadata cache ke payload
        $data['_cache'] = [
            'cached_at' => now()->toIso8601String(),
            'ttl_hours' => (int) round($ttl / 3600),
        ];

        $result = Redis::setex($key, $ttl, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $result !== false;
    }

    /**
     * Hapus cache satu nomor telepon.
     *
     * Dipanggil setelah spam report baru masuk agar data stale dihapus.
     */
    public function invalidate(string $phoneNumber): bool
    {
        $key    = $this->buildKey($phoneNumber);
        $result = Redis::del($key);

        return $result > 0;
    }

    /**
     * Hapus cache banyak nomor sekaligus menggunakan Redis pipeline.
     *
     * Pipeline mengirim semua perintah DEL dalam satu round-trip ke Redis,
     * jauh lebih efisien dibanding loop individual.
     *
     * @param  string[] $phoneNumbers
     * @return int Jumlah key yang berhasil dihapus
     */
    public function invalidateMany(array $phoneNumbers): int
    {
        if (empty($phoneNumbers)) {
            return 0;
        }

        $keys = array_map(
            fn(string $number): string => $this->buildKey($number),
            $phoneNumbers
        );

        // Pipeline: kirim semua DEL dalam satu batch
        $results = Redis::pipeline(function ($pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->del($key);
            }
        });

        // Hitung total key yang benar-benar terhapus (DEL return 1 jika ada, 0 jika tidak)
        return (int) array_sum($results);
    }

    /**
     * Cache-aside pattern: ambil dari cache, jika tidak ada jalankan callback.
     *
     * Alur:
     *   1. Cek cache → jika hit, return data
     *   2. Jika miss → jalankan $callback untuk fetch data fresh
     *   3. Simpan hasil ke cache
     *   4. Return data
     *
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public function remember(string $phoneNumber, Closure $callback): array
    {
        // Coba ambil dari cache terlebih dahulu
        $cached = $this->get($phoneNumber);
        if ($cached !== null) {
            return $cached;
        }

        // Cache miss — ambil data fresh via callback
        $data = $callback();

        // Simpan ke cache jika callback mengembalikan data valid
        if (!empty($data)) {
            $this->put($phoneNumber, $data);
        }

        return $data;
    }

    /**
     * Statistik performa cache nomor telepon.
     *
     * Mengembalikan:
     * - total_cached_numbers : jumlah key phone:* yang ada
     * - cache_hit_rate       : persentase hit dari total request
     * - memory_usage         : estimasi memori Redis (human readable)
     *
     * @return array<string, mixed>
     */
    public function getLookupStats(): array
    {
        // Hitung total nomor yang sedang dicache
        $keys = Redis::keys(self::KEY_PREFIX . '*');

        $hits   = (int) (Redis::get(self::KEY_HIT_COUNTER)  ?? 0);
        $misses = (int) (Redis::get(self::KEY_MISS_COUNTER) ?? 0);
        $total  = $hits + $misses;

        // Hitung hit rate — hindari division by zero
        $hitRate = $total > 0
            ? round(($hits / $total) * 100, 1)
            : 0.0;

        // Info memori Redis dari INFO memory
        $memoryUsage = $this->resolveMemoryUsage();

        return [
            'total_cached_numbers' => count($keys),
            'cache_hit_rate'       => "{$hitRate}%",
            'hits'                 => $hits,
            'misses'               => $misses,
            'total_requests'       => $total,
            'memory_usage'         => $memoryUsage,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Bangun Redis key dari nomor telepon yang sudah dinormalisasi.
     * Contoh: "+6281234567890" → "phone:+6281234567890"
     */
    private function buildKey(string $phoneNumber): string
    {
        $normalized = $this->normalizer->normalize($phoneNumber);

        return self::KEY_PREFIX . $normalized;
    }

    /**
     * Tentukan TTL cache berdasarkan skor spam dan status bisnis.
     *
     * Prioritas:
     *   1. is_business_verified = true → TTL_BUSINESS (48 jam)
     *   2. spam_score >= 70            → TTL_SPAM    (1 jam)
     *   3. spam_score 30–69            → TTL_WARNING (6 jam)
     *   4. spam_score < 30             → TTL_SAFE    (24 jam)
     *
     * @param array<string, mixed> $data
     */
    private function resolveTtl(array $data): int
    {
        // Nomor bisnis terverifikasi — paling stabil
        if (isset($data['is_business_verified']) && $data['is_business_verified'] === true) {
            return self::TTL_BUSINESS;
        }

        $spamScore = (int) ($data['spam_score'] ?? 0);

        return match(true) {
            $spamScore >= 70 => self::TTL_SPAM,
            $spamScore >= 30 => self::TTL_WARNING,
            default          => self::TTL_SAFE,
        };
    }

    /**
     * Ambil estimasi penggunaan memori Redis dari command INFO memory.
     * Return string human-readable, contoh: "12.5 MB".
     */
    private function resolveMemoryUsage(): string
    {
        try {
            $info = Redis::info('memory');

            // phpredis mengembalikan array langsung
            $bytes = (int) ($info['used_memory'] ?? 0);

            if ($bytes === 0) {
                return 'N/A';
            }

            return match(true) {
                $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 1) . ' GB',
                $bytes >= 1_048_576     => round($bytes / 1_048_576, 1)     . ' MB',
                $bytes >= 1_024         => round($bytes / 1_024, 1)         . ' KB',
                default                 => "{$bytes} B",
            };
        } catch (\Throwable) {
            return 'N/A';
        }
    }
}
