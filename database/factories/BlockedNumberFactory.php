<?php

namespace Database\Factories;

use App\Models\BlockedNumber;
use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockedNumber>
 */
class BlockedNumberFactory extends Factory
{
    // Alasan blokir yang realistis untuk Indonesia
    private const REASONS = [
        'Spam telemarketing',
        'Penipuan',
        'Debt collector agresif',
        'Nomor tidak dikenal',
        'Pernah mengganggu',
        'Penipuan berkedok hadiah',
        null, // Sering tidak disertakan alasan
        null,
    ];

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'phone_number_id' => PhoneNumber::factory(),
            'reason'          => fake()->randomElement(self::REASONS),
            //
        ];
    }
}
