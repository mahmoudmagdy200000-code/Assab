<?php

namespace Modules\Aggregator\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Aggregator\Models\Aggregator;

class AggregatorFactory extends Factory
{
    protected $model = Aggregator::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'code' => strtoupper($this->faker->unique()->lexify('???')),
            'logo' => null,
            'description' => $this->faker->sentence(),
            'contact_email' => $this->faker->companyEmail(),
            'contact_phone' => '+9665'.$this->faker->numerify('########'),
            'commission_rate' => $this->faker->randomFloat(2, 5, 30),
            'payment_terms' => $this->faker->randomElement(['Weekly', 'Monthly', 'Bi-Weekly']),
            'is_active' => true,
            'integration_type' => $this->faker->randomElement(['manual', 'api', 'webhook']),
            'api_key' => null,
            'api_endpoint' => null,
            'webhook_url' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withIntegration(): static
    {
        return $this->state(fn (array $attributes) => [
            'integration_type' => 'api',
            'api_key' => \Str::random(32),
            'api_endpoint' => 'https://api.'.strtolower($this->faker->domainName()).'/v1',
            'webhook_url' => 'https://webhook.'.strtolower($this->faker->domainName()).'/callback',
        ]);
    }
}
