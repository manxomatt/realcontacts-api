<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    // Urutan pemanggilan seeder penting: User → PhoneNumber → SpamReport → BusinessProfile → Contact
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PhoneNumberSeeder::class,
            SpamReportSeeder::class,
            BusinessProfileSeeder::class,
            ContactSeeder::class,
        ]);
    }
}

