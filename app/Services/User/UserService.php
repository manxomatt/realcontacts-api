<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Models\User;

/**
 * UserService
 *
 * Mengelola operasi CRUD dan logika bisnis terkait user.
 * Digunakan oleh AuthService untuk registrasi dan pencarian user.
 */
final class UserService
{
    /**
     * Buat user baru di database.
     *
     * @param  array<string, mixed> $data
     */
    public function create(array $data): User
    {
        return User::create($data);
    }

    /**
     * Pastikan nomor telepon belum terdaftar.
     * Melempar exception jika sudah ada.
     *
     * @throws \RuntimeException
     */
    public function ensurePhoneNotRegistered(string $phoneNumber): void
    {
        $exists = User::where('phone_number', $phoneNumber)->exists();

        if ($exists) {
            throw new \RuntimeException(
                "Nomor telepon {$phoneNumber} sudah terdaftar.",
                409
            );
        }
    }

    /**
     * Cari user berdasarkan nomor telepon.
     */
    public function findByPhone(string $phoneNumber): ?User
    {
        return User::where('phone_number', $phoneNumber)->first();
    }
}
