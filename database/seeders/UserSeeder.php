<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Buat 1 akun admin tetap untuk keperluan testing
        User::factory()->create([
            'name'        => 'Admin RealContacts',
            'email'       => 'admin@realcontacts.id',
            'badge_level' => 'platinum',
        ]);

        // Buat 3 user badge bronze — pengguna baru yang belum banyak kontribusi
        User::factory()->count(3)->bronze()->create();

        // Buat 3 user badge silver — pengguna aktif yang mulai berkontribusi
        User::factory()->count(3)->silver()->create();

        // Buat 2 user badge gold — kontributor laporan spam aktif
        User::factory()->count(2)->gold()->create();

        // Buat 1 user badge platinum — kontributor terverifikasi terpercaya
        User::factory()->count(1)->platinum()->create();
    }
}
