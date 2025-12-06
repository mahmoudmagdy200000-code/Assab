<?php

namespace Modules\PurchaseHistory\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class GoodsReceiptFactoryFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = \Modules\PurchaseHistory\Models\GoodsReceiptFactory::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [];
    }
}

