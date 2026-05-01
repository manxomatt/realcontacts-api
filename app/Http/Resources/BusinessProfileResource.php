<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BusinessProfileResource extends JsonResource
{
    public static $wrap = null;

    // Peta key kategori ke label Bahasa Indonesia
    private const CATEGORY_LABELS = [
        'restoran'    => 'Restoran & Kuliner',
        'kurir'       => 'Jasa Kurir & Ekspedisi',
        'ojek_online' => 'Transportasi Online',
        'klinik'      => 'Klinik & Kesehatan',
        'minimarket'  => 'Minimarket & Toko',
        'toko_online' => 'Toko Online',
        'bank'        => 'Bank & Keuangan',
        'asuransi'    => 'Asuransi',
        'hotel'       => 'Hotel & Penginapan',
        'travel'      => 'Travel & Wisata',
    ];

    // Peta singkatan hari ke nama Bahasa Indonesia
    private const DAY_NAMES = [
        'mon' => 'Senin',
        'tue' => 'Selasa',
        'wed' => 'Rabu',
        'thu' => 'Kamis',
        'fri' => 'Jumat',
        'sat' => 'Sabtu',
        'sun' => 'Minggu',
    ];

    /**
     * Transformasi model BusinessProfile ke array response API.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $formattedHours = $this->formatOperatingHours();

        return [
            'business_name'   => $this->business_name,

            // Kategori dalam format yang mudah dibaca
            'category'        => [
                'key'   => $this->category,
                'label' => self::CATEGORY_LABELS[$this->category] ?? $this->category,
            ],

            'description'     => $this->whenNotNull($this->description),
            'address'         => $this->whenNotNull($this->address),

            // Gabungan kota dan provinsi dari accessor location()
            'location'        => $this->location,

            // ─── Jam Operasional ───────────────────────────────────────────────

            // Array human-readable dari JSON operating_hours
            'operating_hours' => $formattedHours,

            // PHP 8.5 array_first() — jam operasional hari ini secara langsung
            'today_hours'     => $this->getTodayScheduleFormatted(),

            // Apakah bisnis sedang buka berdasarkan jam server (WIB)
            'is_open_now'     => $this->isOpenNow(),

            // ─── Rating & Klaim ────────────────────────────────────────────────

            // Float 1 desimal, null jika belum ada rating
            'rating'          => $this->rating !== null
                ? round((float) $this->rating, 1)
                : null,

            'maps_url'        => $this->whenNotNull($this->maps_url),

            // Sudah diklaim pemilik asli atau belum
            'is_claimed'      => $this->is_claimed,

            'is_verified'     => $this->is_verified,
            'verified_at'     => $this->verified_at?->diffForHumans(),

            // ─── Relasi ────────────────────────────────────────────────────────

            // Hanya muncul jika ->with('phoneNumber') dipanggil di Controller
            'phone_number'    => new PhoneNumberResource(
                $this->whenLoaded('phoneNumber')
            ),
        ];
    }

    // ─── Helper Methods ────────────────────────────────────────────────────────

    /**
     * Ubah JSON operating_hours menjadi array human-readable.
     * Input:  {"mon":"08:00-17:00","sun":"closed"}
     * Output: [{"day":"Senin","hours":"08:00 - 17:00","is_open":true}, ...]
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatOperatingHours(): array
    {
        $hours = (array) ($this->operating_hours ?? []);

        return array_values(array_map(
            function (string $dayKey, string $schedule): array {
                $isOpen = $schedule !== 'closed';

                return [
                    'day'     => self::DAY_NAMES[$dayKey] ?? $dayKey,
                    'hours'   => $isOpen
                        // Ganti pemisah "-" dengan " - " agar lebih mudah dibaca
                        ? str_replace('-', ' - ', $schedule)
                        : 'Tutup',
                    'is_open' => $isOpen,
                ];
            },
            array_keys($hours),
            array_values($hours)
        ));
    }

    /**
     * Ambil jadwal hari ini dalam format human-readable.
     * Menggunakan array_first() PHP 8.5 untuk mengambil elemen pertama
     * dari array yang sudah difilter berdasarkan hari saat ini.
     */
    private function getTodayScheduleFormatted(): ?string
    {
        $hours = (array) ($this->operating_hours ?? []);

        if (empty($hours)) {
            return null;
        }

        $todayKey = strtolower(Carbon::now('Asia/Jakarta')->format('D')); // 'mon', 'tue', dst.

        // PHP 8.5 array_first() — ambil nilai pertama dari array yang sudah difilter
        $todaySchedule = array_first(
            array_filter($hours, fn(string $key): bool => $key === $todayKey, ARRAY_FILTER_USE_KEY)
        );

        if ($todaySchedule === null) {
            return null;
        }

        if ($todaySchedule === 'closed') {
            return 'Tutup hari ini';
        }

        return str_replace('-', ' - ', $todaySchedule);
    }

    /**
     * Tentukan apakah bisnis sedang buka berdasarkan jam server WIB.
     * Ambil jadwal hari ini lewat array_first(), parse range jam, lalu bandingkan.
     */
    private function isOpenNow(): bool
    {
        $hours = (array) ($this->operating_hours ?? []);

        if (empty($hours)) {
            return false;
        }

        $now      = Carbon::now('Asia/Jakarta');
        $todayKey = strtolower($now->format('D'));

        // PHP 8.5 array_first() — jadwal hari ini dari operating_hours
        $todaySchedule = array_first(
            array_filter($hours, fn(string $key): bool => $key === $todayKey, ARRAY_FILTER_USE_KEY)
        );

        if (!$todaySchedule || $todaySchedule === 'closed') {
            return false;
        }

        // Parse format "08:00-17:00"
        $parts = explode('-', $todaySchedule);
        if (count($parts) !== 2) {
            return false;
        }

        // Bandingkan sebagai integer HHMM untuk menghindari parsing kompleks
        $currentHHMM = (int) $now->format('Hi');
        $openHHMM    = (int) str_replace(':', '', $parts[0]);
        $closeHHMM   = (int) str_replace(':', '', $parts[1]);

        return $currentHHMM >= $openHHMM && $currentHHMM < $closeHHMM;
    }
}

