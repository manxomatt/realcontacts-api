<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContactTag;
use App\Models\User;

/**
 * ContactTagService
 *
 * Service untuk manajemen contact tags termasuk system tags creation.
 * Menangani operasi tags: create, update, delete, sync.
 */
final class ContactTagService extends BaseService
{
    /**
     * Daftar tag sistem yang harus ada untuk setiap user.
     * Tidak bisa dihapus namun warna/icon bisa diubah.
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
     * Buat default system tags untuk user baru.
     *
     * Membuat 6 tag sistem bawaan saat user pertama kali register.
     * Operasi dilakukan dalam konteks transaksi yang sudah ada di caller.
     *
     * Tags yang dibuat:
     *   - Pelanggan (hijau)
     *   - Supplier (biru)
     *   - Keluarga (pink)
     *   - Rekan Kerja (oranye)
     *   - Penting (merah)
     *   - Junk (abu-abu)
     *
     * @param User $user User yang akan menerima default tags
     * @return void
     */
    public function createDefaultTagsForUser(User $user): void
    {
        // Cek apakah user sudah punya system tags untuk menghindari duplikasi
        $existingSystemTags = ContactTag::where('user_id', $user->id)
            ->where('is_system', true)
            ->count();

        // Skip jika sudah ada system tags
        if ($existingSystemTags > 0) {
            return;
        }

        // Buat setiap system tag untuk user
        foreach (self::SYSTEM_TAGS as $tagData) {
            ContactTag::create([
                'user_id'    => $user->id,
                'name'       => $tagData['name'],
                'color'      => $tagData['color'],
                'icon'       => $tagData['icon'],
                'is_system'  => true,
                'usage_count' => 0,
            ]);
        }
    }
}
