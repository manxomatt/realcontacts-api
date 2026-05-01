<?php

namespace Database\Factories;

use App\Models\PhoneNumber;
use App\Models\SpamReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpamReport>
 */
class SpamReportFactory extends Factory
{
    public function definition(): array
    {
        $upvotes   = fake()->numberBetween(0, 50);
        $downvotes = fake()->numberBetween(0, 20);

        return [
            // Foreign key diisi dari seeder atau buat baru jika standalone
            'phone_number_id' => PhoneNumber::factory(),
            'user_id'         => null,
            'report_type'     => fake()->randomElement(SpamReport::REPORT_TYPES),
            'description'     => fake()->optional(0.5)->sentence(),
            'upvotes'         => $upvotes,
            'downvotes'       => $downvotes,
            'status'          => fake()->randomElement(SpamReport::STATUSES),
        ];
    }

    // Laporan yang dibuat oleh user tertentu (bukan anonim)
    public function byUser(int $userId): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => $userId]);
    }

    // Laporan anonim (user_id null)
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => ['user_id' => null]);
    }

    // Laporan sudah terverifikasi dengan kredibilitas tinggi
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'    => 'verified',
            'upvotes'   => fake()->numberBetween(20, 100),
            'downvotes' => fake()->numberBetween(0, 5),
        ]);
    }
}


