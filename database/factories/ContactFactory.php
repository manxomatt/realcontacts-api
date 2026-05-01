<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'phone_number_id' => fake()->optional(0.7)->randomElement(
                PhoneNumber::pluck('id')->toArray() ?: [null]
            ),
            'name'      => fake()->name(),
            'phone_raw' => fake()->numerify('08##########'),
        ];
    }
}

