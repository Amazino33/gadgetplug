<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id'              => User::factory(),
            'name'                 => fake()->unique()->company(),
            // Matches the column's own default for newly created vendors —
            // an admin has to opt a vendor into online sales explicitly.
            'online_sales_enabled' => false,
        ];
    }

    public function onlineSalesEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'online_sales_enabled' => true,
        ]);
    }
}
