<?php

namespace Database\Factories;

use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(),
            'description' => $this->faker->sentence(),
            'phone' => '08123456789',
            'address' => $this->faker->address(),
            'city_id' => 152,
            'active' => true,
            'plan_code' => 'free',
            'publishes_this_month' => 0,
        ];
    }
}