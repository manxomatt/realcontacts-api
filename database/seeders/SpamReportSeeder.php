<?php

namespace Database\Seeders;

use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Models\User;
use Illuminate\Database\Seeder;

class SpamReportSeeder extends Seeder
{
    public function run(): void
    {
        // Ambil ID berdasarkan kategori skor spam
        $userIds    = User::pluck('id')->toArray();
        $dangerIds  = PhoneNumber::where('spam_score', '>', 70)->pluck('id')->toArray();
        $warningIds = PhoneNumber::whereBetween('spam_score', [30, 70])->pluck('id')->toArray();
        $safeIds    = PhoneNumber::where('spam_score', '<', 30)->pluck('id')->toArray();

        // 50 laporan untuk nomor berbahaya — distribusi terbanyak
        SpamReport::factory()
            ->count(50)
            ->sequence(fn ($seq) => [
                'phone_number_id' => fake()->randomElement($dangerIds),
                'user_id'         => fake()->optional(0.8)->randomElement($userIds),
            ])
            ->create();

        // 35 laporan untuk nomor peringatan
        SpamReport::factory()
            ->count(35)
            ->sequence(fn ($seq) => [
                'phone_number_id' => fake()->randomElement($warningIds),
                'user_id'         => fake()->optional(0.7)->randomElement($userIds),
            ])
            ->create();

        // 15 laporan untuk nomor aman — kemungkinan laporan keliru (false positive)
        SpamReport::factory()
            ->count(15)
            ->sequence(fn ($seq) => [
                'phone_number_id' => fake()->randomElement($safeIds),
                'user_id'         => fake()->optional(0.9)->randomElement($userIds),
                'report_type'     => 'spam', // false report
            ])
            ->create();
    }
}

