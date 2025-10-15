<?php

namespace Modules\Shift\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EndShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total_sales' => 'required|numeric|min:0',
            'cash_collected' => 'required|numeric|min:0',
            'card_payments' => 'required|numeric|min:0',
            'aggregators' => 'nullable|array',
            'aggregators.*.aggregator_id' => 'required|exists:aggregators,id',
            'aggregators.*.amount' => 'required|numeric|min:0',
            'aggregators.*.notes' => 'nullable|string|max:500',
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'total_sales.required' => 'Total sales amount is required',
            'total_sales.numeric' => 'Total sales must be a valid number',
            'total_sales.min' => 'Total sales cannot be negative',
            'cash_collected.required' => 'Cash collected amount is required',
            'card_payments.required' => 'Card payments amount is required',
            'aggregators.*.aggregator_id.required' => 'Aggregator selection is required',
            'aggregators.*.aggregator_id.exists' => 'Selected aggregator does not exist',
            'aggregators.*.amount.required' => 'Aggregator amount is required',
            'pos_receipt.mimes' => 'POS receipt must be JPG, JPEG, PNG or PDF',
            'pos_receipt.max' => 'POS receipt size cannot exceed 5MB',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->shouldValidatePaymentBreakdown()) {
                $this->validatePaymentBreakdown($validator);
            }
        });
    }

    private function shouldValidatePaymentBreakdown(): bool
    {
        return $this->has('total_sales') &&
            $this->has('cash_collected') &&
            $this->has('card_payments');
    }

    private function validatePaymentBreakdown($validator): void
    {
        $totalSales = (float) $this->input('total_sales');
        $cash = (float) $this->input('cash_collected');
        $card = (float) $this->input('card_payments');
        $aggregators = collect($this->input('aggregators', []))->sum('amount');

        $calculatedTotal = $cash + $card + $aggregators;

        // Allow 0.01 difference for rounding
        if (abs($totalSales - $calculatedTotal) > 0.01) {
            $validator->errors()->add(
                'total_sales',
                'Total sales must equal the sum of cash, card, and aggregator payments'
            );
        }
    }
}
