<?php

namespace Modules\Expense\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseAttachment;

class ExpenseAttachmentFactory extends Factory
{
    protected $model = ExpenseAttachment::class;

    public function definition(): array
    {
        $fileTypes = ['pdf', 'jpg', 'png', 'jpeg', 'xlsx', 'docx'];
        $fileType = $this->faker->randomElement($fileTypes);
        $fileName = $this->faker->word().'.'.$fileType;

        return [
            'expense_id' => Expense::factory(),
            'invoice_detail_id' => null,
            'file_path' => 'expenses/'.$this->faker->uuid().'.'.$fileType,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'file_size' => $this->faker->numberBetween(10240, 5242880), // 10KB to 5MB
        ];
    }
}
