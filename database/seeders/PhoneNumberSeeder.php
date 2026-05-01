<?php

namespace Database\Seeders;

use App\Models\PhoneNumber;
use Illuminate\Database\Seeder;

class PhoneNumberSeeder extends Seeder
{
    public function run(): void
    {
        // 20 nomor aman (spam_score 0–29) — semua nomor Indonesia
        PhoneNumber::factory()
            ->count(20)
            ->safe()
            ->indonesia()
            ->create();

        // 15 nomor peringatan Indonesia (spam_score 30–70)
        PhoneNumber::factory()
            ->count(15)
            ->warning()
            ->indonesia()
            ->create();

        // 5 nomor peringatan internasional (+65, +60, +1, +44, +61)
        PhoneNumber::factory()
            ->count(5)
            ->warning()
            ->create();

        // 8 nomor berbahaya Indonesia (spam_score 71–100)
        PhoneNumber::factory()
            ->count(8)
            ->danger()
            ->indonesia()
            ->create();

        // 2 nomor berbahaya internasional
        PhoneNumber::factory()
            ->count(2)
            ->danger()
            ->create();
    }
}

