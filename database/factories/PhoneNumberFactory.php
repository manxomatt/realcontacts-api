<?php

namespace Database\Factories;

use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneNumber>
 */
class PhoneNumberFactory extends Factory
{
    // Prefix nomor Indonesia per operator
    private static array $operatorPrefixes = [
        'Telkomsel' => ['0811', '0812', '0813', '0821', '0822', '0823', '0851', '0852', '0853'],
        'Indosat'   => ['0814', '0815', '0816', '0855', '0856', '0857', '0858'],
        'XL'        => ['0817', '0818', '0819', '0859', '0877', '0878'],
        'Tri'       => ['0895', '0896', '0897', '0898', '0899'],
        'Smartfren' => ['0881', '0882', '0883', '0884', '0885'],
    ];

    // Prefix nomor internasional beserta nama negara
    private static array $internationalPrefixes = [
        '+65' => 'Singapore',
        '+60' => 'Malaysia',
        '+1'  => 'USA',
        '+44' => 'UK',
        '+61' => 'Australia',
    ];

    public function definition(): array
    {
        // 20% kemungkinan nomor internasional
        $isInternational = fake()->boolean(20);

        if ($isInternational) {
            $prefix      = fake()->randomElement(array_keys(self::$internationalPrefixes));
            $rawNumber   = $prefix . fake()->numerify('##########');
            $normalized  = $rawNumber;
            $operator    = self::$internationalPrefixes[$prefix];
            $countryCode = $prefix;
        } else {
            $operator    = fake()->randomElement(array_keys(self::$operatorPrefixes));
            $prefix      = fake()->randomElement(self::$operatorPrefixes[$operator]);
            $rawNumber   = $prefix . fake()->numerify('########');
            $normalized  = '+62' . substr($rawNumber, 1);
            $countryCode = '+62';
        }

        return [
            'phone_number'      => $rawNumber,
            'normalized_number' => $normalized,
            'country_code'      => $countryCode,
            'category'          => fake()->randomElement(['personal', 'business', 'unknown']),
            'owner_name'        => fake()->optional(0.6)->name(),
            'operator_name'     => $operator,
            'spam_score'        => fake()->numberBetween(0, 100),
            'total_reports'     => fake()->numberBetween(0, 50),
            'is_verified'       => fake()->boolean(30),
            'last_updated'      => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }

    // Nomor aman — skor spam di bawah 30
    public function safe(): static
    {
        return $this->state(fn (array $attributes) => [
            'spam_score' => fake()->numberBetween(0, 29),
            'category'   => fake()->randomElement(['personal', 'business']),
        ]);
    }

    // Nomor peringatan — skor spam antara 30 dan 70
    public function warning(): static
    {
        return $this->state(fn (array $attributes) => [
            'spam_score' => fake()->numberBetween(30, 70),
            'category'   => fake()->randomElement(['unknown', 'personal']),
        ]);
    }

    // Nomor berbahaya — skor spam di atas 70
    public function danger(): static
    {
        return $this->state(fn (array $attributes) => [
            'spam_score'   => fake()->numberBetween(71, 100),
            'category'     => 'spam',
            'owner_name'   => fake()->randomElement([
                'Pinjol Ilegal', 'CS Bank Palsu', 'Promo Bodong',
                'Judi Online', 'Penipuan Transfer', 'Scam Crypto',
                'Telemarketer Asuransi', 'Robot Call BRI',
            ]),
        ]);
    }

    // Paksa menggunakan nomor format Indonesia +62
    public function indonesia(): static
    {
        return $this->state(function (array $attributes) {
            $operator   = fake()->randomElement(array_keys(self::$operatorPrefixes));
            $prefix     = fake()->randomElement(self::$operatorPrefixes[$operator]);
            $rawNumber  = $prefix . fake()->numerify('########');

            return [
                'phone_number'      => $rawNumber,
                'normalized_number' => '+62' . substr($rawNumber, 1),
                'country_code'      => '+62',
                'operator_name'     => $operator,
            ];
        });
    }
}

