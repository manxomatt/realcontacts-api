<?php

namespace Database\Factories;

use App\Models\BusinessProfile;
use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfile>
 */
class BusinessProfileFactory extends Factory
{
    // Kategori bisnis Indonesia
    private static array $businessCategories = [
        'restoran', 'kurir', 'ojek_online', 'klinik', 'minimarket',
        'toko_online', 'bank', 'asuransi', 'hotel', 'travel',
    ];

    // Peta provinsi ke daftar kota di dalamnya
    private static array $provinceMap = [
        'DKI Jakarta'      => ['Jakarta Pusat', 'Jakarta Selatan', 'Jakarta Barat', 'Jakarta Timur', 'Jakarta Utara'],
        'Jawa Barat'       => ['Bandung', 'Depok', 'Bekasi', 'Bogor', 'Cimahi'],
        'Jawa Timur'       => ['Surabaya', 'Malang', 'Sidoarjo', 'Gresik'],
        'Jawa Tengah'      => ['Semarang', 'Solo', 'Yogyakarta', 'Magelang'],
        'Banten'           => ['Tangerang', 'Tangerang Selatan', 'Serang'],
        'Sumatera Utara'   => ['Medan', 'Binjai', 'Pematangsiantar'],
        'Sulawesi Selatan' => ['Makassar', 'Parepare', 'Palopo'],
        'Sumatera Selatan' => ['Palembang', 'Prabumulih', 'Lubuklinggau'],
    ];

    // Nama prefix per kategori bisnis
    private static array $businessPrefixes = [
        'restoran'    => ['Warung Makan', 'Restoran', 'RM', 'Café', 'Rumah Makan', 'Warung'],
        'kurir'       => ['Ekspedisi', 'Pengiriman', 'Jasa Kirim', 'Logistik'],
        'ojek_online' => ['Ojek Online', 'Antar Jemput', 'Transportasi Online'],
        'klinik'      => ['Klinik', 'Praktek Dokter', 'Apotek', 'Puskesmas'],
        'minimarket'  => ['Toko', 'Minimarket', 'Warung', 'Kios', 'Swalayan'],
        'toko_online' => ['Toko Online', 'Olshop', 'Official Store'],
        'bank'        => ['Bank', 'BPR', 'Koperasi Simpan Pinjam'],
        'asuransi'    => ['Asuransi', 'Perlindungan Jiwa', 'Agen Proteksi'],
        'hotel'       => ['Hotel', 'Penginapan', 'Guest House', 'Villa', 'Homestay'],
        'travel'      => ['Travel', 'Tour & Travel', 'Agen Wisata', 'Biro Perjalanan'],
    ];

    public function definition(): array
    {
        $province = fake()->randomElement(array_keys(self::$provinceMap));
        $city     = fake()->randomElement(self::$provinceMap[$province]);
        $category = fake()->randomElement(self::$businessCategories);

        return [
            'phone_number_id' => PhoneNumber::factory()->safe(),
            'business_name'   => $this->buildBusinessName($category),
            'category'        => $category,
            'description'     => fake()->optional(0.7)->sentences(2, true),
            'website'         => fake()->optional(0.4)->url(),
            'email'           => fake()->optional(0.6)->companyEmail(),
            'address'         => fake()->streetAddress(),
            'city'            => $city,
            'province'        => $province,
            'is_verified'     => fake()->boolean(40),
            'verified_at'     => fn (array $attrs) => $attrs['is_verified']
                ? fake()->dateTimeBetween('-6 months', 'now')
                : null,
            // JSON jam operasional realistis — Senin-Sabtu buka, Minggu tutup
            'operating_hours' => $this->buildOperatingHours(),
            // Rating 1.0-5.0 dengan 1 desimal, 30% kemungkinan belum ada rating
            'rating'          => fake()->optional(0.7)->randomFloat(1, 1.0, 5.0),
            'maps_url'        => fake()->optional(0.5)->url(),
            'is_claimed'      => fake()->boolean(30),
        ];
    }

    // Bangun nama bisnis realistis berdasarkan kategori
    private function buildBusinessName(string $category): string
    {
        $prefixes = self::$businessPrefixes[$category] ?? ['Bisnis'];
        $prefix   = fake()->randomElement($prefixes);
        $suffix   = fake()->lastName() . ' ' . fake()->randomElement([
            'Jaya', 'Makmur', 'Sejahtera', 'Abadi', 'Maju', 'Barokah', 'Mandiri', 'Bersama',
        ]);

        return $prefix . ' ' . $suffix;
    }

    // Buat jam operasional realistis: Senin-Jumat buka, Sabtu setengah hari, Minggu tutup
    private function buildOperatingHours(): array
    {
        $openHour  = fake()->randomElement(['07:00', '08:00', '09:00', '10:00']);
        $closeHour = fake()->randomElement(['17:00', '18:00', '20:00', '21:00']);
        $weekday   = "{$openHour}-{$closeHour}";

        return [
            'mon' => $weekday,
            'tue' => $weekday,
            'wed' => $weekday,
            'thu' => $weekday,
            'fri' => $weekday,
            'sat' => fake()->boolean(70) ? "{$openHour}-14:00" : 'closed',
            'sun' => fake()->boolean(20) ? "{$openHour}-13:00" : 'closed',
        ];
    }

    // State bisnis yang sudah terverifikasi
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verified_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }
}

