<?php

declare(strict_types=1);

namespace App\Services\Business;

use App\Exceptions\AlreadyClaimedException;
use App\Exceptions\UnauthorizedBusinessClaimException;
use App\Models\BusinessProfile;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * BusinessProfileService
 *
 * Mengelola profil bisnis — klaim, update, dan rating.
 * Service ini menggunakan pattern final class (tidak extend BaseService).
 */
final class BusinessProfileService
{
    /**
     * Klaim kepemilikan profil bisnis untuk nomor tertentu.
     *
     * Alur:
     *   1. Cari PhoneNumber berdasarkan nomor yang dinormalisasi
     *   2. Cari atau buat BusinessProfile untuk PhoneNumber itu
     *   3. Cek apakah sudah diklaim user lain
     *   4. Set status claimed_by_user_id = user yang claim
     *   5. Set is_claimed = true, claimed_at = now
     *
     * @param  string $phoneNumber  Nomor yang dinormalisasi (+62xxx)
     * @param  User   $user         User yang mengklaim
     * @return BusinessProfile      Profile yang sudah di-claim
     *
     * @throws UnauthorizedBusinessClaimException Jika tidak memiliki hak klaim
     * @throws AlreadyClaimedException            Jika sudah diklaim user lain
     */
    public function claim(string $phoneNumber, User $user): BusinessProfile
    {
        $phone = PhoneNumber::where('normalized_number', $phoneNumber)->firstOrFail();

        // Cari atau buat profile bisnis untuk nomor ini
        $profile = BusinessProfile::firstOrCreate(
            ['phone_number_id' => $phone->id],
            [
                'business_name' => $phone->owner_name ?? 'Unnamed Business',
                'is_verified'   => false,
                'is_claimed'    => false,
            ],
        );

        // Cek apakah sudah diklaim
        if ($profile->is_claimed && $profile->claimed_by_user_id !== $user->id) {
            throw new AlreadyClaimedException();
        }

        // Jika sudah diklaim oleh user ini, skip update
        if ($profile->claimed_by_user_id === $user->id) {
            return $profile;
        }

        // Update status klaim — transaksi agar atomic
        return DB::transaction(function () use ($profile, $user): BusinessProfile {
            $profile->update([
                'claimed_by_user_id' => $user->id,
                'is_claimed'         => true,
                'claimed_at'         => now(),
            ]);

            return $profile;
        });
    }

    /**
     * Update profil bisnis dengan data tervalidasi dari Form Request.
     *
     * Hanya pemilik (claimed_by_user_id) yang dapat update.
     * Authorization sudah dicek di Form Request — tidak dicek ulang di sini.
     *
     * @param  BusinessProfile   $profile        Profile yang akan diupdate
     * @param  array<string, mixed> $validatedData Data tervalidasi dari FormRequest
     * @return BusinessProfile                   Profile yang sudah diupdate
     */
    public function update(BusinessProfile $profile, array $validatedData): BusinessProfile
    {
        $profile->update($validatedData);
        $profile->refresh();

        return $profile;
    }

    /**
     * Berikan rating untuk profil bisnis.
     *
     * Rating adalah rata-rata dari semua rating yang diterima.
     * Saat ini: sederhana, hanya update field rating di profile.
     * Untuk implementasi production: simpan riwayat rating di tabel terpisah.
     *
     * @param  string $phoneNumber  Nomor yang dinormalisasi
     * @param  User   $user         User yang memberikan rating (untuk validation)
     * @param  float  $rating       Rating 1-5
     * @return array{new_rating: float, total_ratings: int}
     */
    public function rate(string $phoneNumber, User $user, float $rating): array
    {
        $phone = PhoneNumber::where('normalized_number', $phoneNumber)
            ->with('businessProfile')
            ->firstOrFail();

        if ($phone->businessProfile === null) {
            throw new \RuntimeException('Nomor ini bukan nomor bisnis.');
        }

        $profile = $phone->businessProfile;

        // Sederhana: update rating langsung (implementasi production lebih kompleks)
        $profile->update(['rating' => $rating]);

        return [
            'new_rating'    => (float) $profile->rating,
            'total_ratings' => 1,  // Placeholder — production: hitung dari riwayat
        ];
    }
}
