<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ContactTag;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * SystemTagSeeder
 *
 * Membuat tag sistem bawaan untuk setiap user.
 * Tag sistem tidak bisa dihapus tapi warna/icon bisa diubah.
 *
 * Tag bawaan:
 *   - Pelanggan (#4CAF50, people)
 *   - Supplier (#2196F3, business)
 *   - Keluarga (#E91E63, home)
 *   - Rekan Kerja (#FF9800, work)
 *   - Penting (#F44336, star)
 *   - Junk (#9E9E9E, block)
 */
class SystemTagSeeder extends Seeder
{
    /**
     * Daftar tag sistem yang harus ada untuk setiap user.
     *
     * @var array<array{name: string, color: string, icon: string}>
     */
    private const SYSTEM_TAGS = [
        ['name' => 'Pelanggan', 'color' => '#4CAF50', 'icon' => 'people'],
        ['name' => 'Supplier', 'color' => '#2196F3', 'icon' => 'business'],
        ['name' => 'Keluarga', 'color' => '#E91E63', 'icon' => 'home'],
        ['name' => 'Rekan Kerja', 'color' => '#FF9800', 'icon' => 'work'],
        ['name' => 'Penting', 'color' => '#F44336', 'icon' => 'star'],
        ['name' => 'Junk', 'color' => '#9E9E9E', 'icon' => 'block'],
    ];

    /**
     * Seed the application's database.
     *
     * Membuat tag sistem untuk semua user yang sudah ada.
     * Untuk user baru, call createSystemTagsForUser() di User model atau Observer.
     */
    public function run(): void
    {
        // Iterate semua user
        User::all()->each(fn (User $user) => $this->createSystemTagsForUser($user));
    }

    /**
     * Buat system tags untuk user spesifik.
     *
     * Biasanya dipanggil dari User Observer atau di AuthController saat register.
     *
     * @param User $user User yang akan dapat system tags
     * @return void
     */
    public static function createSystemTagsForUser(User $user): void
    {
        // Cek apakah user sudah punya system tags
        $existingSystemTags = ContactTag::where('user_id', $user->id)
            ->where('is_system', true)
            ->count();

        // Skip jika sudah ada system tags (avoid duplikasi)
        if ($existingSystemTags > 0) {
            return;
        }

        // Buat setiap system tag untuk user
        foreach (self::SYSTEM_TAGS as $tag) {
            ContactTag::create([
                'user_id'  => $user->id,
                'name'     => $tag['name'],
                'color'    => $tag['color'],
                'icon'     => $tag['icon'],
                'is_system' => true,
                'usage_count' => 0,
            ]);
        }
    }
}
