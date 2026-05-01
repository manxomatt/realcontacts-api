<?php

declare(strict_types=1);

namespace App\Services\Phone;

use App\Models\PhoneNumber;
use App\Services\Cache\PhoneCacheService;
use Illuminate\Support\Facades\Redis;

/**
 * BatchLookupService
 *
 * Fitur eksklusif Premium: lookup banyak nomor telepon sekaligus
 * secara efisien dengan satu round-trip Redis dan satu query database.
 *
 * Alur lookup():
 *   1. Normalisasi semua nomor input
 *   2. getCachedNumbers()  → pipeline Redis MGET (1 round-trip)
 *   3. getMissingNumbers() → array_diff cache hits vs input
 *   4. DB whereIn          → 1 query untuk semua nomor yang belum di-cache
 *   5. Merge semua hasil
 *   6. formatBatchResponse() → tambahkan summary
 *
 * Key Redis: "phone:{normalized_number}" (sama dengan PhoneCacheService)
 */
final class BatchLookupService
{
    /** Maksimal nomor per batch request */
    private const MAX_BATCH_SIZE = 10;

    /**
     * Prefix key Redis — harus identik dengan PhoneCacheService::KEY_PREFIX.
     * Dijaga konsisten secara konvensi karena buildKey() di PhoneCacheService private.
     */
    private const CACHE_KEY_PREFIX = 'phone:';

    // ─── Constructor ──────────────────────────────────────────────────────────

    public function __construct(
        private readonly PhoneLookupService     $lookupService,
        private readonly PhoneCacheService      $cacheService,
        private readonly PhoneNormalizerService $normalizer,
    ) {}

    // ─── Public Methods ────────────────────────────────────────────────────────

    /**
     * Lookup banyak nomor telepon sekaligus.
     *
     * Menerima array nomor dalam format apapun (lokal/internasional).
     * Hasilnya di-index oleh normalized_number untuk akses O(1).
     *
     * @param  string[]            $phoneNumbers  Maksimal 10 nomor
     * @return array<string, mixed>               Key = normalized_number
     * @throws \InvalidArgumentException          Jika melebihi MAX_BATCH_SIZE
     */
    public function lookup(array $phoneNumbers): array
    {
        if (count($phoneNumbers) > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(
                'Maksimal ' . self::MAX_BATCH_SIZE . ' nomor per batch request.'
            );
        }

        if (empty($phoneNumbers)) {
            return $this->formatBatchResponse([]);
        }

        // Normalisasi semua nomor input terlebih dahulu
        $normalized = array_map(
            fn(string $raw): string => $this->normalizer->normalize($raw),
            $phoneNumbers
        );
        $normalized = array_values(array_unique($normalized));

        // Langkah 1: Ambil yang ada di cache dalam 1 round-trip Redis
        $cachedData = $this->getCachedNumbers($normalized);

        // Langkah 2: Hitung nomor yang tidak ada di cache
        $missing = $this->getMissingNumbers($normalized, $cachedData);

        // Langkah 3: Fetch nomor yang missing dari DB (1 query whereIn)
        $freshData = $this->fetchMissingFromDatabase($missing);

        // Merge hasil cache dan database
        $allResults = array_merge($cachedData, $freshData);

        // Pastikan semua nomor input tercakup — nomor baru yang tidak ada di DB pun
        $allResults = $this->ensureAllNormalized($normalized, $allResults);

        return $this->formatBatchResponse($allResults);
    }

    /**
     * Ambil data banyak nomor dari cache dalam 1 round-trip Redis.
     *
     * Menggunakan Redis pipeline: semua GET dikirim sekaligus,
     * hasilnya diterima sekaligus — tidak ada N round-trip.
     *
     * @param  string[] $phoneNumbers  Array nomor yang sudah dinormalisasi
     * @return array<string, array<string, mixed>>  Key = normalized_number, value = data
     */
    public function getCachedNumbers(array $phoneNumbers): array
    {
        if (empty($phoneNumbers)) {
            return [];
        }

        // Bangun semua Redis key sekaligus
        $keys = array_map(
            fn(string $n): string => self::CACHE_KEY_PREFIX . $n,
            $phoneNumbers
        );

        // Pipeline: kirim semua GET dalam 1 round-trip
        $rawResults = Redis::pipeline(function ($pipe) use ($keys): void {
            foreach ($keys as $key) {
                $pipe->get($key);
            }
        });

        // Pasangkan nomor dengan hasilnya dan filter yang null (cache miss)
        $result = [];
        foreach ($phoneNumbers as $index => $normalized) {
            $raw = $rawResults[$index] ?? null;

            if ($raw !== null && $raw !== false) {
                $decoded = json_decode($raw, associative: true);
                if (is_array($decoded)) {
                    $result[$normalized] = $decoded;
                }
            }
        }

        return $result;
    }

    /**
     * Hitung nomor yang tidak ada di cache (perlu di-fetch dari DB).
     *
     * Menggunakan array_diff() untuk efisiensi — PHP built-in,
     * lebih cepat dari loop manual untuk array kecil.
     *
     * @param  string[]                           $allNormalized  Semua nomor yang diminta
     * @param  array<string, array<string, mixed>> $cachedData     Hasil getCachedNumbers()
     * @return string[]                                             Nomor yang perlu di-fetch
     */
    public function getMissingNumbers(array $allNormalized, array $cachedData): array
    {
        // array_diff() membandingkan values — keys cachedData adalah nomor ternormalisasi
        $cachedKeys = array_keys($cachedData);

        return array_values(array_diff($allNormalized, $cachedKeys));
    }

    /**
     * Format response batch dengan summary spam level.
     *
     * Menghitung distribusi nomor berdasarkan spam level:
     *   safe    → spam_score < 30
     *   warning → spam_score 30–69
     *   danger  → spam_score ≥ 70
     *   unknown → belum ada data
     *
     * Menggunakan array_first() dan array_last() PHP 8.5
     * untuk mengakses nomor pertama dan terakhir dalam hasil.
     *
     * @param  array<string, array<string, mixed>> $results
     * @return array{summary: array<string, int>, results: array<string, mixed>}
     */
    public function formatBatchResponse(array $results): array
    {
        $summary = [
            'total'   => count($results),
            'safe'    => 0,
            'warning' => 0,
            'danger'  => 0,
            'unknown' => 0,
        ];

        foreach ($results as $data) {
            $level = $data['spam_level'] ?? $this->resolveSpamLevel((int) ($data['spam_score'] ?? -1));

            match ($level) {
                'safe'    => $summary['safe']++,
                'warning' => $summary['warning']++,
                'danger'  => $summary['danger']++,
                default   => $summary['unknown']++,
            };
        }

        // Gunakan array_first() dan array_last() PHP 8.5
        // untuk menandai nomor pertama dan terakhir dalam batch
        $keys = array_keys($results);

        $firstKey = !empty($keys) ? array_first($keys) : null;
        $lastKey  = !empty($keys) ? array_last($keys)  : null;

        // Tambahkan metadata posisi ke setiap entry
        $annotated = [];
        foreach ($results as $phone => $data) {
            $annotated[$phone] = array_merge($data, [
                '_batch' => [
                    'is_first' => $phone === $firstKey,
                    'is_last'  => $phone === $lastKey,
                ],
            ]);
        }

        return [
            'summary' => $summary,
            'results' => $annotated,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Fetch nomor yang tidak ada di cache dari database (1 query whereIn).
     *
     * Setelah fetch, simpan ke cache via PhoneCacheService::put()
     * agar request berikutnya bisa langsung hit cache.
     *
     * Nomor yang tidak ada di DB sama sekali akan di-lookupOrCreate()
     * secara individual — kasus ini jarang terjadi di produksi.
     *
     * @param  string[] $missing  Array normalized_number yang tidak ada di cache
     * @return array<string, array<string, mixed>>
     */
    private function fetchMissingFromDatabase(array $missing): array
    {
        if (empty($missing)) {
            return [];
        }

        // Satu query untuk semua nomor yang missing — bukan N query
        $phones = PhoneNumber::whereIn('normalized_number', $missing)
            ->with([
                // Ambil 5 laporan spam terbaru yang sudah terverifikasi
                'spamReports' => fn($q) => $q->where('status', 'verified')
                                              ->latest()
                                              ->limit(5),
                'businessProfile',
            ])
            ->get()
            ->keyBy('normalized_number');

        $result = [];

        foreach ($missing as $normalized) {
            if (isset($phones[$normalized])) {
                $phone      = $phones[$normalized];
                $formatted  = $this->formatPhoneEntry($phone);
                $result[$normalized] = $formatted;

                // Simpan ke cache untuk request berikutnya
                $this->cacheService->put($normalized, $formatted);
            } else {
                // Nomor belum ada di DB — buat entry baru via lookupOrCreate
                $phone      = $this->lookupService->lookupOrCreate($normalized);
                $formatted  = $this->formatPhoneEntry($phone);
                $result[$normalized] = $formatted;

                $this->cacheService->put($normalized, $formatted);
            }
        }

        return $result;
    }

    /**
     * Pastikan semua nomor input tercakup dalam hasil.
     *
     * Jika ada nomor yang tidak ada di $allResults (edge case),
     * tambahkan placeholder kosong agar response selalu lengkap.
     *
     * @param  string[]                            $normalized
     * @param  array<string, array<string, mixed>> $allResults
     * @return array<string, array<string, mixed>>
     */
    private function ensureAllNormalized(array $normalized, array $allResults): array
    {
        foreach ($normalized as $phone) {
            if (!array_key_exists($phone, $allResults)) {
                $allResults[$phone] = [
                    'phone_number'  => $phone,
                    'spam_score'    => 0,
                    'spam_level'    => 'unknown',
                    'owner_name'    => null,
                    'total_reports' => 0,
                    'is_verified'   => false,
                    '_not_found'    => true,
                ];
            }
        }

        return $allResults;
    }

    /**
     * Format satu PhoneNumber model menjadi array untuk response dan cache.
     *
     * @param  PhoneNumber $phone
     * @return array<string, mixed>
     */
    private function formatPhoneEntry(PhoneNumber $phone): array
    {
        $spamScore = (int) $phone->spam_score;

        return [
            'id'               => $phone->id,
            'phone_number'     => $phone->phone_number,
            'country_code'     => $phone->country_code,
            'owner_name'       => $phone->owner_name,
            'operator_name'    => $phone->operator_name,
            'category'         => $phone->category,
            'spam_score'       => $spamScore,
            'spam_level'       => $this->resolveSpamLevel($spamScore),
            'total_reports'    => (int) $phone->total_reports,
            'is_verified'      => (bool) $phone->is_verified,
            'last_updated'     => $phone->last_updated?->toIso8601String(),
            'spam_reports'     => ($phone->relationLoaded('spamReports')
                ? $phone->spamReports->map(fn($r) => [
                    'id'          => $r->id,
                    'report_type' => $r->report_type,
                    'description' => $r->description,
                    'upvotes'     => $r->upvotes,
                    'downvotes'   => $r->downvotes,
                    'created_at'  => $r->created_at?->toIso8601String(),
                ])->toArray()
                : []),
            'business_profile' => ($phone->relationLoaded('businessProfile') && $phone->businessProfile)
                ? [
                    'business_name' => $phone->businessProfile->business_name,
                    'category'      => $phone->businessProfile->category,
                ]
                : null,
        ];
    }

    /**
     * Tentukan spam level dari skor numerik.
     *
     * Diinline agar BatchLookupService tidak perlu inject SpamDetectionService.
     * Threshold identik dengan SpamDetectionService::getSpamLevel().
     *
     * @param  int    $score  -1 jika tidak diketahui
     * @return string 'safe' | 'warning' | 'danger' | 'unknown'
     */
    private function resolveSpamLevel(int $score): string
    {
        return match (true) {
            $score < 0  => 'unknown',
            $score >= 70 => 'danger',
            $score >= 30 => 'warning',
            default      => 'safe',
        };
    }
}
