<?php

namespace Database\Seeders;

use App\Models\BusinessProfile;
use App\Models\PhoneNumber;
use Illuminate\Database\Seeder;

class BusinessProfileSeeder extends Seeder
{
    // Satu profil bisnis per kategori bisnis Indonesia
    private array $categories = [
        'restoran', 'kurir', 'ojek_online', 'klinik', 'minimarket',
        'toko_online', 'bank', 'asuransi', 'hotel', 'travel',
    ];

    public function run(): void
    {
        // Ambil 10 nomor aman yang belum memiliki profil bisnis
        $safeNumbers = PhoneNumber::where('spam_score', '<', 30)
            ->whereDoesntHave('businessProfile')
            ->take(10)
            ->get();

        // Buat satu profil bisnis per nomor dengan kategori berbeda-beda
        foreach ($safeNumbers as $index => $phone) {
            BusinessProfile::factory()->create([
                'phone_number_id' => $phone->id,
                'category'        => $this->categories[$index % count($this->categories)],
            ]);
        }
    }
}

