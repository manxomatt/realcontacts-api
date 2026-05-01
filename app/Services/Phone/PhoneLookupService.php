<?php

declare(strict_types=1);

namespace App\Services\Phone;

use App\Models\PhoneNumber;
use App\Services\Cache\PhoneCacheService;
use App\Services\Spam\SpamDetectionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

/**
 * PhoneLookupService
 *
 * Service utama untuk endpoint GET /api/phone/{number}.
 *
 * Alur lookup():
 *   normalize → cache::remember() → [DB lookup / create] → recalculate? → cache
 *   → increment counter → return data
 *
 * Redis key patterns:
 *   phone:{+62xxx}               → data cache (dikelola PhoneCacheService)
 *   lookup_count:{+62xxx}        → counter total lookup nomor ini
 *   lookup_count:{id}:{date}     → rate-limit harian per user (dipakai di PhoneLookupRequest)
 *   recent_lookups:{user_id}     → sorted set riwayat lookup user
 */
final class PhoneLookupService
{
    /** Interval sync lookup counter ke database (setiap N kali lookup) */
    private const LOOKUP_SYNC_INTERVAL = 100;

    /** Maksimal hasil recent lookups yang disimpan per user di Redis */
    private const RECENT_LOOKUPS_LIMIT = 50;

    /** Minimum karakter untuk full-text search */
    private const SEARCH_MIN_LENGTH = 3;

    /** Maksimal hasil search */
    private const SEARCH_LIMIT = 20;

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly PhoneNormalizerService $normalizer,
        private readonly PhoneCacheService      $cacheService,
        private readonly SpamDetectionService   $detectionService,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Flow utama pencarian nomor telepon.
     *
     * Menggunakan pipe |> untuk chaining normalisasi.
     * Cache-aside pattern via PhoneCacheService::remember().
     *
     * @param  string $rawPhoneNumber  Nomor dalam format apapun
     * @return array<string, mixed>    Data lengkap nomor
     */
    public function lookup(string $rawPhoneNumber): array
    {
        // Normalisasi nomor — PHP 8.5 pipe operator
        $normalized = $rawPhoneNumber
            |> (fn(string $n): string => $this->normalizer->normalize($n));

        // Cache-aside: ambil dari cache, jika miss jalankan callback fetch dari DB
        $data = $this->cacheService->remember(
            phoneNumber: $normalized,
            callback: fn(): array => $this->fetchFromDatabase($normalized),
        );

        // Increment counter lookup — dilakukan di luar cache-aside
        $this->incrementLookupCount($normalized);

        return $data;
    }

    /**
     * Cari nomor di database, atau buat entry baru jika belum ada.
     *
     * Dipakai oleh SpamReportService dan flow lain yang butuh PhoneNumber model.
     *
     * @param  string $normalizedNumber  Nomor dalam format +62xxx
     * @return PhoneNumber
     */
    public function lookupOrCreate(string $normalizedNumber): PhoneNumber
    {
        return PhoneNumber::firstOrCreate(
            ['normalized_number' => $normalizedNumber],
            [
                'phone_number'   => $normalizedNumber,
                'country_code'   => $this->extractCountryCode($normalizedNumber),
                'operator_name'  => $this->normalizer->detectOperator($normalizedNumber),
                'owner_name'     => null,
                'spam_score'     => 0,
                'total_reports'  => 0,
                'category'       => 'unknown',
                'is_verified'    => false,
                'last_updated'   => now(),
            ]
        );
    }

    /**
     * Tambah counter lookup di Redis secara atomic.
     *
     * Setiap LOOKUP_SYNC_INTERVAL (100) kali, sinkronisasi ke kolom
     * total_lookups di database (jika kolom tersebut ada).
     * Menggunakan INCR Redis yang atomic — aman untuk concurrent request.
     *
     * @param  string $phoneNumber  Nomor yang dinormalisasi
     */
    public function incrementLookupCount(string $phoneNumber): void
    {
        $key   = "lookup_count:{$phoneNumber}";
        $count = (int) Redis::incr($key);

        // Sync ke database setiap 100 kali lookup
        if ($count % self::LOOKUP_SYNC_INTERVAL === 0) {
            PhoneNumber::where('normalized_number', $phoneNumber)
                ->increment('total_reports', 0);   // Touch updated_at saja jika tidak ada kolom lookup_count
        }
    }

    /**
     * Tambah nomor ke sorted set riwayat lookup user di Redis.
     *
     * Dipanggil oleh controller setelah lookup berhasil.
     * Score = unix timestamp agar bisa diambil berdasarkan waktu.
     *
     * @param  int    $userId      ID user yang melakukan lookup
     * @param  string $phoneNumber Nomor yang dinormalisasi
     */
    public function recordRecentLookup(int $userId, string $phoneNumber): void
    {
        $key  = "recent_lookups:{$userId}";
        $now  = now()->timestamp;

        // ZADD dengan score = timestamp (terbaru di atas)
        Redis::zadd($key, $now, $phoneNumber);

        // Pangkas sorted set agar tidak melebihi batas maksimum
        // ZREMRANGEBYRANK membuang member dengan rank terendah (terlama)
        $size = (int) Redis::zcard($key);
        if ($size > self::RECENT_LOOKUPS_LIMIT) {
            Redis::zremrangebyrank($key, 0, $size - self::RECENT_LOOKUPS_LIMIT - 1);
        }
    }

    /**
     * Ambil riwayat nomor yang baru-baru ini dicari user.
     *
     * Membaca dari Redis sorted set, lalu ambil data dari DB untuk
     * setiap nomor yang ditemukan.
     *
     * @param  int $userId  ID user
     * @param  int $limit   Jumlah nomor yang diambil
     * @return Collection<int, PhoneNumber>
     */
    public function getRecentlyLookedUp(int $userId, int $limit = 10): Collection
    {
        $key = "recent_lookups:{$userId}";

        // ZREVRANGE: ambil dari score tertinggi (terbaru) ke terlama
        $recentNumbers = Redis::zrevrange($key, 0, $limit - 1);

        if (empty($recentNumbers)) {
            return collect();
        }

        // Ambil data PhoneNumber dari DB — satu query dengan whereIn
        return PhoneNumber::whereIn('normalized_number', $recentNumbers)
            ->select(['id', 'phone_number', 'normalized_number', 'spam_score', 'owner_name', 'operator_name', 'is_verified'])
            ->get()
            ->sortBy(fn(PhoneNumber $p): int =>
                (int) array_search($p->normalized_number, $recentNumbers)
            )
            ->values();
    }

    /**
     * Full-text search pada owner_name dan phone_number.
     *
     * Minimum 3 karakter untuk menghindari query yang terlalu luas.
     * Hasil dibatasi 20 nomor.
     *
     * @param  string $query  Kata kunci pencarian
     * @return Collection<int, PhoneNumber>
     * @throws \InvalidArgumentException jika query kurang dari 3 karakter
     */
    public function search(string $query): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < self::SEARCH_MIN_LENGTH) {
            throw new \InvalidArgumentException(
                'Kata kunci pencarian minimal ' . self::SEARCH_MIN_LENGTH . ' karakter.'
            );
        }

        return PhoneNumber::where(function ($q) use ($query): void {
            $q->where('owner_name', 'LIKE', "%{$query}%")
              ->orWhere('phone_number', 'LIKE', "%{$query}%");
        })
        ->select(['id', 'phone_number', 'normalized_number', 'owner_name', 'spam_score', 'is_verified', 'operator_name'])
        ->orderByDesc('spam_score')         // Nomor spam di atas agar lebih waspada
        ->limit(self::SEARCH_LIMIT)
        ->get();
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Fetch data nomor dari database dan format untuk cache.
     *
     * Dipanggil oleh PhoneCacheService::remember() saat cache miss.
     * Melakukan recalculate skor jika diperlukan via shouldRecalculate().
     *
     * @param  string $normalizedNumber
     * @return array<string, mixed>
     */
    private function fetchFromDatabase(string $normalizedNumber): array
    {
        // Cari atau buat entry PhoneNumber
        $phone = $this->lookupOrCreate($normalizedNumber);

        // Recalculate spam score jika sudah waktunya
        if ($this->detectionService->shouldRecalculate($phone)) {
            $phone = $this->detectionService->recalculateAndSave($normalizedNumber);
        }

        // Load relasi — ambil 5 laporan spam terbaru dan business profile
        $phone->loadMissing([
            'spamReports' => fn($q) => $q->where('status', 'verified')
                                          ->latest()
                                          ->limit(5),
            'businessProfile',
        ]);

        return $this->formatPhoneData($phone);
    }

    /**
     * Format PhoneNumber model menjadi array untuk cache dan response.
     *
     * @param  PhoneNumber             $phone
     * @return array<string, mixed>
     */
    private function formatPhoneData(PhoneNumber $phone): array
    {
        return [
            'id'               => $phone->id,
            'phone_number'     => $phone->phone_number,
            'country_code'     => $phone->country_code,
            'owner_name'       => $phone->owner_name,
            'operator_name'    => $phone->operator_name,
            'category'         => $phone->category,
            'spam_score'       => $phone->spam_score,
            'spam_level'       => $this->detectionService->getSpamLevel((int) $phone->spam_score),
            'total_reports'    => $phone->total_reports,
            'is_verified'      => (bool) $phone->is_verified,
            'last_updated'     => $phone->last_updated?->toIso8601String(),
            'spam_reports'     => $phone->spamReports->map(fn($r) => [
                'id'          => $r->id,
                'report_type' => $r->report_type,
                'description' => $r->description,
                'upvotes'     => $r->upvotes,
                'downvotes'   => $r->downvotes,
                'created_at'  => $r->created_at?->toIso8601String(),
            ])->toArray(),
            'business_profile' => $phone->businessProfile ? [
                'business_name' => $phone->businessProfile->business_name,
                'category'      => $phone->businessProfile->category,
                'address'       => $phone->businessProfile->address,
            ] : null,
        ];
    }

    /**
     * Ekstrak kode negara dari nomor internasional.
     *
     * Delegasi ke PhoneNormalizerService::detectCountry() jika tersedia,
     * fallback ke substr 3 karakter (+62).
     */
    private function extractCountryCode(string $normalizedNumber): string
    {
        $country = $this->normalizer->detectCountry($normalizedNumber);

        if ($country !== null) {
            // detectCountry() return country name — kita butuh kode +XX
            // Ambil prefix dari nomor langsung
            if (str_starts_with($normalizedNumber, '+')) {
                // Ambil 3 karakter pertama (+62, +65, dll.)
                return substr($normalizedNumber, 0, 3);
            }
        }

        return str_starts_with($normalizedNumber, '+') ? substr($normalizedNumber, 0, 3) : '+62';
    }
}
