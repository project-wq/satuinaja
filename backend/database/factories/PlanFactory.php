<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'code' => 'plan-'.$this->faker->unique()->numberBetween(1, 999999),
            'name' => $this->faker->word(),
            'price_monthly' => 0,
            'max_products' => 100,
            'max_channels' => 5,
            'max_publishes_monthly' => 500,
            'ai_caption' => false,
            'auto_publish' => false,
            'active' => true,
        ];
    }
}