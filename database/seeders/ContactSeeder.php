<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Seeder;

class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $users       = User::all();
        $phoneIds    = PhoneNumber::pluck('id')->toArray();

        // Setiap user menyimpan antara 3–7 kontak di phonebook-nya
        foreach ($users as $user) {
            $count = fake()->numberBetween(3, 7);

            Contact::factory()
                ->count($count)
                ->sequence(fn ($seq) => [
                    'user_id'         => $user->id,
                    'phone_number_id' => fake()->optional(0.7)->randomElement($phoneIds),
                    'phone_raw'       => fake()->numerify('08##########'),
                ])
                ->create();
        }
    }
}

