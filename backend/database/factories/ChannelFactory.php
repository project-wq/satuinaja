<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'merchant_id' => MerchantFactory::new(),
            'platform' => 'facebook',
            'label' => $this->faker->sentence(3),
            'active' => false,
            'credentials' => [],
            'last_sync_at' => null,
            'last_error' => null,
        ];
    }
}