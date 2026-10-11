<?php

namespace Modules\Shift\Http\Requests;

use App\Support\ShiftFinancialCalculator;
use App\Support\ShiftMoneyValidation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for ending a shift
 *
 * Supports all 4 options:
 * - Option 1: End Shift Only (without handover)
 * - Option 2: End Shift with Handover (no variance)
 * - Option 3: End Shift with Handover and Variance
 * - Option 4: End Shift with Handover, Variance, and Responsibility Assignment
 */
class EndShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Sales Information (Required)
            'total_sales' => 'required|'.ShiftMoneyValidation::SAR,

            // Payment Breakdown
            'cash_collected' => 'sometimes|'.ShiftMoneyValidation::SAR,
            'card_payments' => 'sometimes|'.ShiftMoneyValidation::SAR,

            // Payment Aggregators (Delivery Apps)
            'aggregators' => 'sometimes|array',
            'aggregators.*.aggregator_id' => 'required_with:aggregators|exists:aggregators,id',
            'aggregators.*.amount' => 'required_with:aggregators|'.ShiftMoneyValidation::SAR,
            'aggregators.*.notes' => 'nullable|string|max:255',

            // POS Receipt
            'pos_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB max

            // Handover Information (Optional - for end with handover)
            'with_handover' => 'sometimes|boolean',
            'handover_to_type' => 'required_if:with_handover,true|in:cashier,branch_manager',
            'next_cashier_id' => 'required_if:handover_to_type,cashier|nullable|exists:cashiers,id',
            'handover_amount' => 'required_if:with_handover,true|nullable|'.ShiftMoneyValidation::SAR,
            'handover_notes' => 'nullable|string|max:500',

            // Variance Information (Optional - for shifts with variance)
            'variance' => 'sometimes|array',
            'variance.responsibility_type' => [
                'required_with:variance',
                Rule::in(['self', 'self_and_others', 'other_factors', 'mixed']),
            ],

            // Self responsibility amount (for shared variance)
            'variance.current_cashier_amount' => 'required_if:variance.responsibility_type,self_and_others,mixed|nullable|'.ShiftMoneyValidation::SAR,

            // Other cashiers responsibility (for shared variance)
            'variance.other_cashiers' => 'sometimes|array',
            'variance.other_cashiers.*.cashier_id' => 'required_with:variance.other_cashiers|exists:cashiers,id',
            'variance.other_cashiers.*.amount' => 'required_with:variance.other_cashiers|'.ShiftMoneyValidation::SAR,
            'variance.other_cashiers.*.notes' => 'nullable|string|max:255',

            // External factors reason (for other_factors and mixed)
            'variance.reason' => 'required_if:variance.responsibility_type,other_factors,mixed|nullable|string|max:500',
            'variance.external_reason' => 'nullable|string|max:500',

            // Supporting files (for other_factors and mixed)
            'variance.supporting_files' => 'sometimes|array',
            'variance.supporting_files.*' => 'file|mimes:pdf,png,jpeg,jpg|max:5120',
        ];
    }

    public function messages(): array
    {
        return [
            'total_sales.required' => 'Total sales amount is required.',
            'total_sales.numeric' => 'Total sales must be a valid number.',
            'total_sales.min' => 'Total sales cannot be negative.',

            'handover_to_type.required_if' => 'Please specify who to hand over to (cashier or branch_manager).',
            'next_cashier_id.required_if' => 'Please select the next cashier for handover.',
            'next_cashier_id.exists' => 'The selected cashier does not exist.',
            'handover_amount.required_if' => 'Handover amount is required when doing handover.',

            'variance.responsibility_type.required_with' => 'Please specify variance responsibility type.',
            'variance.responsibility_type.in' => 'Invalid responsibility type. Must be: self, self_and_others, other_factors, or mixed.',
            'variance.current_cashier_amount.required_if' => 'Your responsibility amount is required for shared variance.',
            'variance.reason.required_if' => 'A reason is required for external factors variance.',

            'pos_receipt.mimes' => 'POS receipt must be a JPG, JPEG, PNG, or PDF file.',
            'pos_receipt.max' => 'POS receipt must not exceed 5MB.',
            'variance.supporting_files.*.mimes' => 'Supporting files must be PDF, PNG, or JPEG.',
            'variance.supporting_files.*.max' => 'Each supporting file must not exceed 5MB.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        ShiftMoneyValidation::normalizeRepresentationNoise($this);

        // Set default values
        if (! $this->has('cash_collected')) {
            $this->merge(['cash_collected' => 0]);
        }
        if (! $this->has('card_payments')) {
            $this->merge(['card_payments' => 0]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate payment breakdown matches total sales (with small tolerance for rounding)
            if (! $validator->errors()->has('total_sales')) {
                $this->validatePaymentBreakdown($validator);
            }

            // Validate variance amounts if shared responsibility
            if ($this->has('variance') && ! $validator->errors()->any()) {
                $this->validateVarianceAmounts($validator);
            }
        });
    }

    /**
     * Validate that payment breakdown equals total sales
     */
    private function validatePaymentBreakdown($validator): void
    {
        $totalSales = (float) $this->input('total_sales', 0);
        $cash = (float) $this->input('cash_collected', 0);
        $card = (float) $this->input('card_payments', 0);
        $aggregators = collect($this->input('aggregators', []))->sum('amount');

        $calculatedTotal = $cash + $card + $aggregators;
        $tolerance = 0.01; // Allow 1 cent difference for rounding

        if (abs($totalSales - $calculatedTotal) > $tolerance) {
            $validator->errors()->add(
                'payment_breakdown',
                "Payment breakdown ({$calculatedTotal}) does not match total sales ({$totalSales}). Difference: ".abs($totalSales - $calculatedTotal)
            );
        }
    }

    /**
     * Validate variance amounts for shared responsibility
     */
    private function validateVarianceAmounts($validator): void
    {
        $responsibilityType = $this->input('variance.responsibility_type');

        if (in_array($responsibilityType, ['self_and_others', 'mixed'])) {
            $currentCashierAmount = (float) $this->input('variance.current_cashier_amount', 0);
            $otherCashiersTotal = collect($this->input('variance.other_cashiers', []))->sum('amount');

            // Calculate expected variance from sales and handover
            $totalSales = (float) $this->input('total_sales', 0);
            $handoverAmount = (float) $this->input('handover_amount', 0);
            $expectedVariance = abs($totalSales - $handoverAmount);

            $assignedTotal = $currentCashierAmount + $otherCashiersTotal;

            // For mixed factors, the assigned amount can be less than total variance
            // (remainder goes to external factors)
            if ($responsibilityType === 'self_and_others' && abs($assignedTotal - $expectedVariance) > 0.01) {
                $validator->errors()->add(
                    'variance.total_assignment',
                    "Assigned variance amounts ({$assignedTotal}) should equal total variance ({$expectedVariance})."
                );
            }
        }
    }

    /**
     * Get validated data with calculated fields
     */
    public function validatedWithCalculations(): array
    {
        $validated = $this->validated();

        // Extract VAT from the inclusive total through the shared calculation.
        $totalSales = $validated['total_sales'];
        $salesCalculation = ShiftFinancialCalculator::calculateVatInclusiveSales($totalSales);
        $vatAmount = (float) $salesCalculation['vat'];
        $netSales = (float) $salesCalculation['net'];

        $validated['vat_amount'] = $vatAmount;
        $validated['net_sales'] = $netSales;

        // Calculate variance if handover exists
        if (isset($validated['handover_amount'])) {
            $handoverAmount = (float) $validated['handover_amount'];
            $cash = (float) ($validated['cash_collected'] ?? 0);
            $validated['variance_amount'] = $cash - $handoverAmount;
            $validated['variance_type'] = $validated['variance_amount'] > 0 ? 'over' : ($validated['variance_amount'] < 0 ? 'short' : 'none');
        }

        return $validated;
    }
}
